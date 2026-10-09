<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Docker\ContainerStatus;
use App\Docker\DockerApi;
use App\Docker\DockerException;
use App\Docker\DockerRefused;
use App\Docker\LogOutput;
use Override;

/**
 * The Docker seam, as a script.
 *
 * Not a stub that returns nothing: a small model of the daemon's observable
 * behaviour — volumes exist once created, a container runs once started and
 * stops when killed — plus a log of every call. Integration tests read that
 * log to assert what the broker asked Docker to do, which is the only kind of
 * Docker assertion this suite makes. Failures are scripted per method
 * (`failNext`), so the error paths stay reachable without a daemon.
 */
final class ScriptedDockerApi implements DockerApi
{
    /** @var array<string, array<string, string>> volume name → labels */
    private array $volumes = [];

    /** @var array<string, DockerException> method name → the failure it throws next */
    private array $failures = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array{name: string, payload: array<string, mixed>, started: bool, finished: bool, exitCode: ?int}> */
    private array $containers = [];

    private int $containersCreated = 0;

    /**
     * The next call to `$method` throws this instead of doing its job. One
     * shot, so a scripted failure cannot leak into the following test.
     */
    public function failNext(string $method, DockerException $failure): void
    {
        $this->failures[$method] = $failure;
    }

    public function hasVolume(string $name): bool
    {
        return isset($this->volumes[$name]);
    }

    /**
     * @return array<string, string>
     */
    public function labelsOfVolume(string $name): array
    {
        return $this->volumes[$name] ?? [];
    }

    /**
     * @return list<array{method: string, args: array<string, mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<string>
     */
    public function calledMethods(): array
    {
        return array_map(static fn (array $call): string => (string) $call['method'], $this->calls);
    }

    /**
     * Forgets the recorded calls, so a test can script a precondition and then
     * assert on exactly what the request under test caused.
     */
    public function resetCalls(): void
    {
        $this->calls = [];
    }

    #[Override]
    public function createContainer(string $name, array $payload): string
    {
        $this->record('createContainer', ['name' => $name, 'payload' => $payload]);
        $this->maybeFail('createContainer');

        $id = \sprintf('scripted-container-%04d', ++$this->containersCreated);

        $this->containers[$id] = [
            'name' => $name,
            'payload' => $payload,
            'started' => false,
            'finished' => false,
            'exitCode' => null,
        ];

        return $id;
    }

    #[Override]
    public function startContainer(string $id): void
    {
        $this->record('startContainer', ['id' => $id]);
        $this->maybeFail('startContainer');
        $this->mustHave($id);

        $this->containers[$id]['started'] = true;
    }

    #[Override]
    public function inspectContainer(string $id): ContainerStatus
    {
        $this->record('inspectContainer', ['id' => $id]);
        $this->maybeFail('inspectContainer');
        $this->mustHave($id);

        $container = $this->containers[$id];

        if ($container['finished']) {
            return ContainerStatus::stopped($container['exitCode']);
        }

        return ContainerStatus::running();
    }

    #[Override]
    public function killContainer(string $id): void
    {
        $this->record('killContainer', ['id' => $id]);
        $this->maybeFail('killContainer');

        // Tolerated by the interface, so the script tolerates it too: killing
        // an unknown or already-stopped container is not an error.
        if (!isset($this->containers[$id]) || $this->containers[$id]['finished']) {
            return;
        }

        $this->containers[$id]['finished'] = true;
        $this->containers[$id]['exitCode'] = 137;
    }

    #[Override]
    public function removeContainer(string $id): void
    {
        $this->record('removeContainer', ['id' => $id]);
        $this->maybeFail('removeContainer');

        unset($this->containers[$id]);
    }

    #[Override]
    public function containerLogs(string $id, int $maxBytes): LogOutput
    {
        $this->record('containerLogs', ['id' => $id, 'maxBytes' => $maxBytes]);
        $this->maybeFail('containerLogs');
        $this->mustHave($id);

        // No logs are scripted yet: nothing in the broker reads them until the
        // exec endpoint lands, and inventing a scripting surface now would be
        // guessing at what it needs.
        return new LogOutput();
    }

    #[Override]
    public function createVolume(string $name, array $labels): void
    {
        $this->record('createVolume', ['name' => $name, 'labels' => $labels]);
        $this->maybeFail('createVolume');

        $this->volumes[$name] = $labels;
    }

    #[Override]
    public function volumeExists(string $name): bool
    {
        $this->record('volumeExists', ['name' => $name]);
        $this->maybeFail('volumeExists');

        return isset($this->volumes[$name]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    private function maybeFail(string $method): void
    {
        $failure = $this->failures[$method] ?? null;

        if (null === $failure) {
            return;
        }

        unset($this->failures[$method]);

        throw $failure;
    }

    private function mustHave(string $id): void
    {
        if (!isset($this->containers[$id])) {
            throw DockerRefused::because('no such container: '.$id);
        }
    }
}
