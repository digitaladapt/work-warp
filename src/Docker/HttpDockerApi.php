<?php

declare(strict_types=1);

namespace App\Docker;

use InvalidArgumentException;
use JsonException;
use Override;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The Docker client: HttpDockerApi over the socket proxy, nothing else.
 *
 * Every URL is built here from an id or a name, never accepted from a caller —
 * the method set is the whole of what the broker can ask the daemon to do. All
 * failures leave as one of the two typed refusals the API layer already maps:
 * `DockerUnavailable` when the daemon could not be reached (503) and
 * `DockerRefused` when it was reached and said no (502), with the daemon's own
 * words in the message wherever it has any.
 *
 * The log reader is the one method with real machinery in it. `GET
 * /containers/{id}/logs` on a non-TTY container answers in Docker's multiplexed
 * stream format — an 8-byte header per frame, `[stream(1), pad(3), size(4 BE)]`,
 * which is the same framing `docker logs` demultiplexes — and the broker reads
 * it as a stream rather than buffering the response first, because a runaway
 * command's output is exactly the thing that must not be read without a cap.
 * The cap is per stream and reading stops the moment one is hit: draining a
 * megabyte of output to then discard it is a memory leak with extra steps.
 */
final class HttpDockerApi implements DockerApi
{
    /**
     * `[stream(1), pad(3), size(4 BE)]`, per Docker's multiplexed stream format.
     */
    private const int FRAME_HEADER_BYTES = 8;

    /**
     * A sanity bound on a single frame. Real frames are line-sized; a frame
     * header claiming megabytes means the framing is not what it says it is,
     * and buffering towards it would be obeying a claim nobody verified.
     */
    private const int MAX_FRAME_BYTES = 4_194_304;

    /**
     * How much of a daemon error message is kept. Enough for "No such image:
     * ghcr.io/…" and its pull errors, not enough to paste a wall into a log.
     */
    private const int MAX_ERROR_CHARS = 500;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $dockerHost,
    ) {
    }

    #[Override]
    public function createContainer(string $name, array $payload): string
    {
        $response = $this->call('POST', '/containers/create', [
            'query' => ['name' => $name],
            'json' => $payload,
        ]);

        if (201 !== $this->status($response)) {
            throw $this->refused('create the container', $response);
        }

        $id = $this->decoded($response)['Id'] ?? null;

        if (!\is_string($id) || '' === $id) {
            throw DockerRefused::because('Docker created the container but reported no id for it');
        }

        return $id;
    }

    #[Override]
    public function startContainer(string $id): void
    {
        $response = $this->call('POST', '/containers/'.$this->segment($id).'/start');

        // 304 "Container already started" is the desired end state, and a broker
        // restart must be able to adopt a container it had already started.
        $status = $this->status($response);

        if (204 !== $status && 304 !== $status) {
            throw $this->refused('start the container', $response);
        }
    }

    #[Override]
    public function inspectContainer(string $id): ContainerStatus
    {
        $response = $this->call('GET', '/containers/'.$this->segment($id).'/json');

        if (200 !== $this->status($response)) {
            throw $this->refused('inspect the container', $response);
        }

        return ContainerStatus::fromApiResponse($this->decoded($response));
    }

    #[Override]
    public function killContainer(string $id): void
    {
        $response = $this->call('POST', '/containers/'.$this->segment($id).'/kill');

        // 404 (already gone) and 409 (already stopped) both mean "it is not
        // running now", which is what killing was for.
        $status = $this->status($response);

        if (204 !== $status && 404 !== $status && 409 !== $status) {
            throw $this->refused('kill the container', $response);
        }
    }

    #[Override]
    public function removeContainer(string $id): void
    {
        $response = $this->call('DELETE', '/containers/'.$this->segment($id), [
            'query' => ['force' => '1'],
        ]);

        $status = $this->status($response);

        if (204 !== $status && 404 !== $status) {
            throw $this->refused('remove the container', $response);
        }
    }

    #[Override]
    public function containerLogs(string $id, int $maxBytes): LogOutput
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException(\sprintf('maxBytes must be at least 1, got %d', $maxBytes));
        }

        $response = $this->call('GET', '/containers/'.$this->segment($id).'/logs', [
            'query' => ['stdout' => '1', 'stderr' => '1'],
        ]);

        if (200 !== $this->status($response)) {
            throw $this->refused('read the container logs', $response);
        }

        $stdout = '';
        $stderr = '';
        $truncated = false;
        $buffer = '';
        $completed = false;

        try {
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout() || $chunk->isFirst() || $chunk->isLast()) {
                    continue;
                }

                $buffer .= $chunk->getContent();

                // Frames can split across chunks at any byte, so parse from a
                // buffer and stop when what is left is shorter than a header.
                while (\strlen($buffer) >= self::FRAME_HEADER_BYTES) {
                    $stream = \ord($buffer[0]);

                    if (1 !== $stream && 2 !== $stream) {
                        throw DockerRefused::because(\sprintf('the Docker log stream is not in the documented multiplexed format: a frame begins with stream id %d, and this broker only creates non-TTY containers whose frames are 1 or 2', $stream));
                    }

                    $size = $this->frameSize(substr($buffer, 4, 4));

                    if (\strlen($buffer) < self::FRAME_HEADER_BYTES + $size) {
                        break;
                    }

                    $payload = substr($buffer, self::FRAME_HEADER_BYTES, $size);
                    $buffer = substr($buffer, self::FRAME_HEADER_BYTES + $size);

                    if (2 === $stream) {
                        [$stderr, $cut] = self::append($stderr, $payload, $maxBytes);
                    } else {
                        [$stdout, $cut] = self::append($stdout, $payload, $maxBytes);
                    }

                    if ($cut) {
                        $truncated = true;
                        break;
                    }
                }

                if ($truncated) {
                    break;
                }
            }

            // A read that stopped early is not a completed read: the transfer
            // is cancelled below rather than drained, because everything past
            // the cap has already been discarded.
            $completed = !$truncated;
        } catch (TransportExceptionInterface $e) {
            // Partial output beats no output: a command that ran must not look
            // like it never started because the log read died. Zero bytes is
            // the only case with nothing worth keeping.
            if ('' === $stdout && '' === $stderr) {
                throw DockerUnavailable::because('lost the Docker daemon while reading the container logs: '.$e->getMessage());
            }

            $truncated = true;
        } finally {
            if (!$completed) {
                try {
                    $response->cancel();
                } catch (TransportExceptionInterface) {
                    // The read already ended; there is nothing left to stop.
                }
            }
        }

        return new LogOutput($stdout, $stderr, $truncated);
    }

    #[Override]
    public function networkExists(string $name): bool
    {
        $response = $this->call('GET', '/networks/'.$this->segment($name));
        $status = $this->status($response);

        if (200 === $status) {
            return true;
        }

        if (404 === $status) {
            return false;
        }

        throw $this->refused('look up the session network', $response);
    }

    #[Override]
    public function createNetwork(string $name, array $labels): void
    {
        $response = $this->call('POST', '/networks/create', [
            'json' => ['Name' => $name, 'Labels' => $labels],
        ]);

        if (201 !== $this->status($response)) {
            throw $this->refused('create the session network', $response);
        }
    }

    #[Override]
    public function createVolume(string $name, array $labels): void
    {
        $response = $this->call('POST', '/volumes/create', [
            'json' => ['Name' => $name, 'Labels' => $labels],
        ]);

        if (201 !== $this->status($response)) {
            throw $this->refused('create the workspace volume', $response);
        }
    }

    #[Override]
    public function volumeExists(string $name): bool
    {
        $response = $this->call('GET', '/volumes/'.$this->segment($name));
        $status = $this->status($response);

        if (200 === $status) {
            return true;
        }

        if (404 === $status) {
            return false;
        }

        throw $this->refused('look up the workspace volume', $response);
    }

    /**
     * The proxy address, normalised, or a typed refusal saying why there isn't
     * one.
     *
     * `tcp://` is Docker's spelling of "over the network" and becomes `http://`
     * here; a `unix://` socket is refused by name rather than by accident,
     * because the broker reaching the daemon socket directly is the exact thing
     * the proxy exists to prevent (SECURITY.md).
     */
    private function baseUrl(): string
    {
        $host = trim($this->dockerHost);

        if ('' === $host) {
            throw DockerUnavailable::because('WW_DOCKER_HOST is not set, so the broker has no Docker daemon to talk to');
        }

        if (str_starts_with($host, 'unix:')) {
            throw DockerUnavailable::because('WW_DOCKER_HOST points at a unix socket; the broker reaches Docker through the TCP proxy instead, and never the daemon socket itself');
        }

        if (str_starts_with($host, 'tcp://')) {
            $host = 'http://'.substr($host, 6);
        }

        if (!str_starts_with($host, 'http://') && !str_starts_with($host, 'https://')) {
            throw DockerUnavailable::because(\sprintf('WW_DOCKER_HOST must be a tcp://, http:// or https:// address, got "%s"', $this->dockerHost));
        }

        return rtrim($host, '/');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function call(string $method, string $path, array $options = []): ResponseInterface
    {
        $base = $this->baseUrl();

        try {
            return $this->http->request($method, $base.$path, $options);
        } catch (TransportExceptionInterface $e) {
            throw DockerUnavailable::because(\sprintf('could not reach the Docker daemon at %s: %s', $base, $e->getMessage()));
        }
    }

    private function status(ResponseInterface $response): int
    {
        try {
            return $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw DockerUnavailable::because('lost the Docker daemon: '.$e->getMessage());
        }
    }

    private function body(ResponseInterface $response): string
    {
        try {
            // `false`: an error status is data here, not an exception — the
            // daemon's explanation is the useful part.
            return $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw DockerUnavailable::because('lost the Docker daemon: '.$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(ResponseInterface $response): array
    {
        $body = $this->body($response);

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DockerRefused::because('Docker answered with a body that is not JSON: '.$this->excerpt($body));
        }

        if (!\is_array($decoded)) {
            throw DockerRefused::because('Docker answered with JSON that is not an object');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function refused(string $action, ResponseInterface $response): DockerRefused
    {
        $detail = $this->excerpt($this->body($response));

        return DockerRefused::because(\sprintf(
            'Docker refused to %s (%d)%s',
            $action,
            $this->status($response),
            '' === $detail ? '' : ': '.$detail,
        ));
    }

    /**
     * A daemon error, in the daemon's own words where it has any.
     */
    private function excerpt(string $body): string
    {
        $message = trim($body);

        try {
            $decoded = json_decode($message, true, 512, \JSON_THROW_ON_ERROR);

            if (\is_array($decoded) && \is_string($decoded['message'] ?? null)) {
                $message = $decoded['message'];
            }
        } catch (JsonException) {
            // Not JSON; the raw body is the message.
        }

        if (\strlen($message) > self::MAX_ERROR_CHARS) {
            return substr($message, 0, self::MAX_ERROR_CHARS).'...';
        }

        return $message;
    }

    private function frameSize(string $bytes): int
    {
        $unpacked = unpack('N', $bytes);
        $size = false === $unpacked ? 0 : (int) $unpacked[1];

        if ($size > self::MAX_FRAME_BYTES) {
            throw DockerRefused::because(\sprintf('a Docker log frame declares %d bytes, beyond the %d-byte limit this client will buffer', $size, self::MAX_FRAME_BYTES));
        }

        return $size;
    }

    /**
     * Append until the stream's cap is reached, and say whether anything was cut.
     *
     * @return array{string, bool}
     */
    private static function append(string $existing, string $payload, int $maxBytes): array
    {
        $room = $maxBytes - \strlen($existing);

        if ($room <= 0) {
            return [$existing, '' !== $payload];
        }

        if (\strlen($payload) <= $room) {
            return [$existing.$payload, false];
        }

        return [$existing.substr($payload, 0, $room), true];
    }

    /**
     * One path segment, encoded. Ids and names this broker builds are already
     * safe; encoding anyway means a caller-supplied string can never change the
     * shape of the URL that carries it.
     */
    private function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
