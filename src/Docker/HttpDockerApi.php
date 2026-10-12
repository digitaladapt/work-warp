<?php

declare(strict_types=1);

namespace App\Docker;

use App\Build\BuildOutput;
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
    public function removeContainer(string $id, bool $removeAnonymousVolumes = false): void
    {
        $query = ['force' => '1'];

        if ($removeAnonymousVolumes) {
            $query['v'] = '1';
        }

        $response = $this->call('DELETE', '/containers/'.$this->segment($id), [
            'query' => $query,
        ]);

        $status = $this->status($response);

        if (204 !== $status && 404 !== $status) {
            throw $this->refused('remove the container', $response);
        }
    }

    #[Override]
    public function listContainers(): array
    {
        // `all=1` so exited containers are visible: they are the ones that
        // leak volumes and disk, and PLAN.md §8's second rule is entirely
        // about them. The filter is this broker's own label, built here.
        $response = $this->call('GET', '/containers/json', [
            'query' => [
                'all' => '1',
                'filters' => json_encode(
                    ['label' => [Labels::LABEL_CREATED_BY.'='.Labels::CREATED_BY]],
                    \JSON_THROW_ON_ERROR,
                ),
            ],
        ]);

        if (200 !== $this->status($response)) {
            throw $this->refused('list the containers', $response);
        }

        $listed = $this->decoded($response);
        $containers = [];

        foreach ($listed as $entry) {
            if (!\is_array($entry)) {
                throw DockerRefused::because('the daemon listed a container that is not an object');
            }

            $containers[] = ContainerSummary::fromApiSummary($entry);
        }

        return $containers;
    }

    #[Override]
    public function stopContainer(string $id): void
    {
        // No `t`: the container's own StopTimeout — set at create by
        // ContainerSpec — is the sequence's budget, and the daemon's stop is
        // already SIGTERM, wait, SIGKILL (verified against moby's stop.go).
        $response = $this->call('POST', '/containers/'.$this->segment($id).'/stop');

        $status = $this->status($response);

        // 304 is "already stopped" and 404 is "already gone": both are the
        // desired end state of a stop, and a sweep racing another cleaner must
        // not read either as a failure.
        if (204 !== $status && 304 !== $status && 404 !== $status) {
            throw $this->refused('stop the container', $response);
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

    /**
     * The build reader.
     *
     * `POST /build` answers with a stream of JSON progress lines — one object
     * per line, `{"stream":"…"}`, `{"error":"…"}`, `{"status":"…"}`,
     * `{"aux":…}` — and the broker reads it the way it reads container logs:
     * streamed, parsed line by line, and *capped*, because a chatty build is
     * the same memory leak as a chatty command.
     *
     * Three classifications, each measured against Docker 29.8 rather than
     * assumed (all three probed by hand while building this):
     *
     * - **`{"error":…}` in a `200` stream is the daemon reporting a failed
     *   build** ("unshare: operation not permitted", "unexpected EOF"). It is
     *   *not* an HTTP failure — the status is 200 — so it cannot come back as
     *   `DockerRefused`; it becomes `failed: true` in the result and the
     *   daemon's own words in the output.
     * - **A bad query is an HTTP 500 with `{"message":…}`** (measured:
     *   malformed `labels`, invalid tag forms). That is the daemon refusing the
     *   request, and leaves as `DockerRefused` — the broker's 502.
     * - **Running out of time is the connection being cut.** The budget is set
     *   as the client's *idle* timeout (`timeout`), and an idle-timeout trip
     *   yields an `isTimeout()` chunk and ends the stream — measured, including
     *   the part that matters: setting it cuts the connection where a shorter
     *   one would have cut a merely *quiet* build. The daemon documents that a
     *   build whose client disconnects is cancelled. The absolute timeout
     *   (`max_duration`, the same number) is the belt for a stream that keeps
     *   trickling yet never finishes; its trip arrives as a transport
     *   exception whose message says "Operation timed out" (measured wording —
     *   curl's, and stable), which the reader classifies as a timeout. Both
     *   trip styles mean the same thing: the broker's own clock ended the
     *   read.
     *
     * A lost connection with *some* output already read is a cut read — the
     * caller keeps what was streamed and the flags say the rest; with
     * *nothing* read, the daemon was never really reached and that is
     * `DockerUnavailable`, exactly as the log reader treats it.
     */
    #[Override]
    public function buildImage(array $query, mixed $context, float $timeoutSeconds, int $maxOutputBytes): BuildOutput
    {
        if ($maxOutputBytes < 1) {
            throw new InvalidArgumentException(\sprintf('maxOutputBytes must be at least 1, got %d', $maxOutputBytes));
        }

        $response = $this->call('POST', '/build', [
            'query' => $query,
            'headers' => ['Content-Type' => 'application/x-tar'],
            'body' => $context,
            // The build budget: idle timeout and absolute ceiling are the same
            // number, so a build may be quiet for at most its whole budget and
            // may run for at most its whole budget. `max_duration` doubles as
            // the machine-readable marker for "this connection was cut by our
            // own clock" when curl raises, which is why it must be set.
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
        ]);

        if (200 !== $this->status($response)) {
            throw $this->refused('run the build', $response);
        }

        return $this->readBuildStream($response, $maxOutputBytes);
    }

    #[Override]
    public function imageExists(string $reference): bool
    {
        $response = $this->call('GET', '/images/'.$this->segment($reference).'/json');
        $status = $this->status($response);

        if (200 === $status) {
            return true;
        }

        if (404 === $status) {
            return false;
        }

        throw $this->refused('look up the built image', $response);
    }

    #[Override]
    public function pullImage(string $image, float $timeoutSeconds, int $maxOutputBytes): PullOutput
    {
        if ($maxOutputBytes < 1) {
            throw new InvalidArgumentException(\sprintf('maxOutputBytes must be at least 1, got %d', $maxOutputBytes));
        }

        $response = $this->call('POST', '/images/create', [
            'query' => [
                'fromImage' => ImageReference::name($image),
                'tag' => ImageReference::tag($image),
            ],
            // The same clock shape as buildImage: idle and absolute are the
            // same number, and the daemon treats a client disconnect as
            // cancelling the operation.
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
        ]);

        if (200 !== $this->status($response)) {
            throw $this->refused('pull the image', $response);
        }

        return $this->readPullStream($response, $maxOutputBytes);
    }

    #[Override]
    public function listVolumes(): array
    {
        // The same discipline as listContainers: the filter is this broker's
        // own label, built here and never passed in, so the stack teardown
        // cannot be aimed at a neighbour's volume either.
        $response = $this->call('GET', '/volumes', [
            'query' => [
                'filters' => json_encode(
                    ['label' => [Labels::LABEL_CREATED_BY.'='.Labels::CREATED_BY]],
                    \JSON_THROW_ON_ERROR,
                ),
            ],
        ]);

        if (200 !== $this->status($response)) {
            throw $this->refused('list the volumes', $response);
        }

        $listed = $this->decoded($response)['Volumes'] ?? null;

        if (null === $listed) {
            // The daemon answers `{"Volumes": null}` when the filter matches
            // nothing; that is an empty set, not a malformed answer.
            return [];
        }

        if (!\is_array($listed)) {
            throw DockerRefused::because('the daemon listed volumes that are not an array');
        }

        $volumes = [];

        foreach ($listed as $entry) {
            if (!\is_array($entry)) {
                throw DockerRefused::because('the daemon listed a volume that is not an object');
            }

            $volumes[] = VolumeSummary::fromApiVolume($entry);
        }

        return $volumes;
    }

    #[Override]
    public function removeVolume(string $name): void
    {
        $response = $this->call('DELETE', '/volumes/'.$this->segment($name));

        $status = $this->status($response);

        // 404 is "already gone", the desired end state; anything else — in
        // particular the daemon's container-conflict refusal — is real.
        if (204 !== $status && 404 !== $status) {
            throw $this->refused('remove the volume', $response);
        }
    }

    /**
     * Read a pull's stream: `docker pull`'s JSON progress lines, kept for
     * their *errors* only.
     *
     * A pull's progress is megabytes of layer bookkeeping that no caller needs
     * — the useful remainder is what went wrong. So `{"error":…}` lines are
     * kept and everything else is read and dropped, bounded by the same cap as
     * everything else. The read behaves exactly like the build reader's (see
     * `readBuildStream` and its measurements): the idle and absolute budget
     * are the same number, a cap hit does *not* cancel (cancelling disconnects
     * the client, and the daemon treats that as cancelling the pull), and only
     * the clock or a broken connection cancels.
     */
    private function readPullStream(ResponseInterface $response, int $maxOutputBytes): PullOutput
    {
        $output = '';
        $truncated = false;
        $timedOut = false;
        $failed = false;
        $buffer = '';
        $drain = false;
        $cancel = false;

        try {
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    $timedOut = true;
                    $cancel = true;

                    break;
                }

                if ($chunk->isFirst() || $chunk->isLast()) {
                    continue;
                }

                $buffer .= $chunk->getContent();

                while (false !== ($newline = strpos($buffer, "\n"))) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);

                    $piece = self::readPullLine($line);

                    if ('' === $piece) {
                        continue;
                    }

                    $failed = true;

                    if ($drain) {
                        continue;
                    }

                    [$output, $cut] = self::append($output, $piece, $maxOutputBytes);

                    if ($cut) {
                        $truncated = true;
                        $drain = true;
                    }
                }

                if (!$drain && \strlen($buffer) > $maxOutputBytes) {
                    $truncated = true;
                    $drain = true;
                    $buffer = '';
                }
            }
        } catch (TransportExceptionInterface $e) {
            if ('' === $output && !$failed) {
                throw DockerUnavailable::because('lost the Docker daemon while pulling the image: '.$e->getMessage());
            }

            $cancel = true;

            if (str_contains($e->getMessage(), 'timed out')) {
                $timedOut = true;
            } else {
                $truncated = true;
            }
        } finally {
            if ($cancel) {
                try {
                    $response->cancel();
                } catch (TransportExceptionInterface) {
                    // The read already ended; there is nothing left to stop.
                }
            }
        }

        return new PullOutput($output, $truncated, $timedOut, $failed);
    }

    /**
     * One pull progress line: kept only when it is an error.
     *
     * (`errorDetail` carries the same text as `error` in the daemon's stream;
     * reading `error` alone keeps the kept output one line per failure rather
     * than two.)
     */
    private static function readPullLine(string $line): string
    {
        $line = trim($line);

        if ('' === $line) {
            return '';
        }

        try {
            $decoded = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }

        if (!\is_array($decoded)) {
            return '';
        }

        $error = $decoded['error'] ?? null;

        if (\is_string($error) && '' !== $error) {
            return 'error: '.$error."\n";
        }

        return '';
    }

    /**
     * Read the build progress until it ends, runs out of budget, or fills the
     * cap.
     *
     * Split on `\n` from a buffer, not per network chunk: a chunk boundary may
     * fall in the middle of a JSON line at any byte, and treating half a line
     * as a line is how a reader reports garbage. Lines that are not JSON, or
     * JSON that is not an object, are dropped rather than fatal — the daemon
     * owns this format and a future field must not break the reader — while an
     * `{"error":…}` is recorded as the failure it is.
     *
     * Two details are load-bearing, and both were measured rather than
     * reasoned about:
     *
     * - **Hitting the output cap does not stop the read.** Cancelling the
     *   response would disconnect the client — which is how the daemon cancels
     *   a build, deliberately. A log cap must never kill the build it is
     *   capping, so past the cap this keeps reading and stops keeping: the
     *   build runs to its own end and `truncated` says the log is short. (That
     *   is the difference from the log reader, where stopping early is free
     *   because the container does not care who is listening.)
     * - **Only the clock, or a broken connection, cancels.** The budget is
     *   enforced by disconnecting — the one stop this endpoint has — so the
     *   cancel happens exactly when the broker has decided the build is over.
     */
    private function readBuildStream(ResponseInterface $response, int $maxOutputBytes): BuildOutput
    {
        $output = '';
        $truncated = false;
        $timedOut = false;
        $failed = false;
        $buffer = '';
        $drain = false;
        $cancel = false;

        try {
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    // The idle timeout tripped and the stream ends here: the
                    // build was cut by the broker's own clock (measured; see
                    // the method above). Whatever was read is kept; the flag
                    // says the rest.
                    $timedOut = true;
                    $cancel = true;

                    break;
                }

                if ($chunk->isFirst() || $chunk->isLast()) {
                    continue;
                }

                $buffer .= $chunk->getContent();

                while (false !== ($newline = strpos($buffer, "\n"))) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);

                    [$piece, $isError] = self::readBuildLine($line);

                    if ($isError) {
                        // Recorded even while draining: a build that failed
                        // must not read as merely quiet because its output was
                        // long.
                        $failed = true;
                    }

                    if ('' === $piece || $drain) {
                        continue;
                    }

                    [$output, $cut] = self::append($output, $piece, $maxOutputBytes);

                    if ($cut) {
                        $truncated = true;
                        $drain = true;
                    }
                }

                if (\strlen($buffer) > $maxOutputBytes) {
                    // A partial line longer than the whole cap: no future
                    // append could keep any of it, and buffering towards a
                    // newline is exactly the unbounded read this cap exists to
                    // prevent. Dropped in both modes — while draining, complete
                    // lines are still parsed (so a late `error` is still
                    // recorded); it is only the unparseable tail that goes.
                    if (!$drain) {
                        $truncated = true;
                        $drain = true;
                    }

                    $buffer = '';
                }
            }
        } catch (TransportExceptionInterface $e) {
            if ('' === $output && !$failed) {
                throw DockerUnavailable::because('lost the Docker daemon while streaming the build: '.$e->getMessage());
            }

            $cancel = true;

            // Two ways a read ends without a last chunk, told apart by curl's
            // own wording (measured: an absolute-budget trip says "Operation
            // timed out after N milliseconds"). A read that broke any other
            // way is not a timeout — the log is simply incomplete, and the
            // image lookup in the runner still answers the real question.
            if (str_contains($e->getMessage(), 'timed out')) {
                $timedOut = true;
            } else {
                $truncated = true;
            }
        } finally {
            if ($cancel) {
                try {
                    $response->cancel();
                } catch (TransportExceptionInterface) {
                    // The read already ended; there is nothing left to stop.
                }
            }
        }

        return new BuildOutput($output, $truncated, $timedOut, $failed);
    }

    /**
     * One progress line, reduced to what the caller needs to see.
     *
     * `stream` and `status` are kept (they are the build's own narration —
     * "Step 1/3", "Successfully built abc123"); `error` is kept and marked;
     * `aux` is dropped (BuildKit's trace ids, written to stderr by the CLI).
     *
     * @return array{string, bool} the text to keep, and whether it was an error
     */
    private static function readBuildLine(string $line): array
    {
        $line = trim($line);

        if ('' === $line) {
            return ['', false];
        }

        try {
            $decoded = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['', false];
        }

        if (!\is_array($decoded)) {
            return ['', false];
        }

        $error = $decoded['error'] ?? null;

        if (\is_string($error) && '' !== $error) {
            return ['error: '.$error."\n", true];
        }

        foreach (['stream', 'status'] as $key) {
            $value = $decoded[$key] ?? null;

            if (\is_string($value) && '' !== $value) {
                // `stream` values already end in their own newline (the daemon
                // sends the raw stdout fragment); `status` values are single
                // lines without one. Normalised here so the kept output reads
                // as lines rather than running together.
                return [str_ends_with($value, "\n") ? $value : $value."\n", false];
            }
        }

        return ['', false];
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
