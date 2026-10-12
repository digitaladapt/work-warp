<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Build\BuildOutput;
use App\Docker\ContainerStatus;
use App\Docker\ContainerSummary;
use App\Docker\DockerApi;
use App\Docker\DockerRefused;
use App\Docker\LogOutput;
use App\Docker\PullOutput;
use App\Docker\VolumeSummary;
use Override;
use Throwable;

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

    /** @var array<string, array<string, string>> network name → labels */
    private array $networks = [];

    /** @var array<string, Throwable> method name → the failure it throws next */
    private array $failures = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array{name: string, payload: array<string, mixed>, started: bool, finished: bool, exitCode: ?int, logs: ?LogOutput}> */
    private array $containers = [];

    private int $containersCreated = 0;

    /**
     * Containers that exist without having been created through this script —
     * the janitor's subjects. They carry the summary fields the daemon would
     * report, because that is exactly what the sweep reads.
     *
     * @var list<ContainerSummary>
     */
    private array $listed = [];

    /**
     * How the next started container behaves: `finish` ends by itself on the
     * first inspection with the given exit code; `linger` runs until killed.
     *
     * This is the script's answer to "what does the command do" — the one
     * thing about a real container a test cannot otherwise decide, because
     * the whole point of the suite is that no container runs.
     *
     * @var array{behaviour: string, exitCode: int}
     */
    private array $runScript = ['behaviour' => 'finish', 'exitCode' => 0];

    /**
     * How the next build behaves: what its stream carries, and whether the
     * tag exists afterwards.
     *
     * The two are independent on purpose — `output.timedOut` with
     * `tagged: true` is the race where the build completed exactly as the
     * clock cut the stream, and it must be scriptable because it is the case
     * the runner's image lookup exists for.
     *
     * @var array{output: ?BuildOutput, tagged: bool}
     */
    private array $buildScript = ['output' => null, 'tagged' => false];

    /** @var array<string, bool> image reference → exists */
    private array $images = [];

    /**
     * How pulls behave until re-scripted: what the stream carries, and
     * whether the pull *produces* the image it was asked for. The default is
     * a successful silent pull — the common case — and the runner confirms
     * pulls with `imageExists`, so `produces: false` models the silent
     * failure the runner must still detect.
     *
     * @var array{output: string, truncated: bool, timedOut: bool, failed: bool, produces: bool}
     */
    private array $pullScript = ['output' => '', 'truncated' => false, 'timedOut' => false, 'failed' => false, 'produces' => true];

    /** @var array<string, array<string, string>> volume name → labels, for listVolumes */
    private array $volumeLabels = [];

    /**
     * Script the next build: this stream result, and whether the tag exists
     * afterwards (i.e. whether the build produced an image).
     */
    public function scriptNextBuild(BuildOutput $output, bool $tagged): void
    {
        $this->buildScript = ['output' => $output, 'tagged' => $tagged];
    }

    /**
     * The common case in one call: a build whose stream carries this output,
     * ending successfully or with a reported failure.
     */
    public function runBuildToCompletion(string $output, bool $tagged, bool $failed = false, string $error = ''): void
    {
        $this->scriptNextBuild(
            new BuildOutput($failed ? $output.'error: '.$error."\n" : $output, false, false, $failed),
            $tagged,
        );
    }

    /**
     * Seed an image that exists without having been built — the fixture for
     * "the image was already there" cases (and for `up` after `build`: the
     * scratch image is already present, so no pull happens).
     */
    public function seedImage(string $reference): void
    {
        $this->images[$reference] = true;
    }

    /**
     * Script pulls until re-scripted: these stream facts, and whether a pull
     * makes the image present (`produces: false` models the silent failure).
     * The image marked present is the one the pull was *asked for* — the call
     * argument — so tests never repeat an image name.
     */
    public function scriptNextPull(PullOutput $output, bool $produces = true): void
    {
        $this->pullScript = [
            'output' => $output->output,
            'truncated' => $output->truncated,
            'timedOut' => $output->timedOut,
            'failed' => $output->failed,
            'produces' => $produces,
        ];
    }

    /**
     * A volume as both `GET /volumes` and `GET /volumes/{name}` would
     * describe it, for teardown tests.
     *
     * @param array<string, string> $labels
     */
    public function seedVolume(string $name, array $labels): void
    {
        $this->volumes[$name] = $labels;
        $this->volumeLabels[$name] = $labels;
    }

    /**
     * A container the janitor should see, as `GET /containers/json` would
     * describe it. Seeded directly rather than created through the command
     * lifecycle, because the sweep's subjects are containers the broker did
     * *not* just create — that is the entire point of a janitor.
     *
     * @param array<string, string> $labels
     */
    public function seedContainer(
        string $id,
        string $name,
        bool $running,
        int $createdAt,
        array $labels,
    ): void {
        $this->listed[] = ContainerSummary::fromApiSummary([
            'Id' => $id,
            'Names' => ['/'.$name],
            'State' => $running ? 'running' : 'exited',
            'Created' => $createdAt,
            'Labels' => $labels,
        ]);
    }

    /**
     * The next created container finishes by itself with this exit code, on
     * its first inspection.
     */
    public function runToCompletion(int $exitCode = 0): void
    {
        $this->runScript = ['behaviour' => 'finish', 'exitCode' => $exitCode];
    }

    /**
     * The next created container runs until it is killed — the shape of a
     * command that ignores its deadline.
     */
    public function linger(): void
    {
        $this->runScript = ['behaviour' => 'linger', 'exitCode' => 0];
    }

    /**
     * The output the next started container's logs read back as.
     */
    public function scriptNextLogs(LogOutput $logs): void
    {
        $this->nextLogs = $logs;
    }

    private ?LogOutput $nextLogs = null;

    /**
     * The next call to `$method` throws this instead of doing its job. One
     * shot, so a scripted failure cannot leak into the following test.
     */
    public function failNext(string $method, Throwable $failure): void
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

        // The run script applies at *start*, not at create: a container that
        // never started is not a container that finished.
        $this->containers[$id]['started'] = true;

        if ('finish' === $this->runScript['behaviour']) {
            $this->containers[$id]['finished'] = true;
            $this->containers[$id]['exitCode'] = $this->runScript['exitCode'];
        }

        if (null !== $this->nextLogs) {
            $this->containers[$id]['logs'] = $this->nextLogs;
            $this->nextLogs = null;
        }
    }

    #[Override]
    public function inspectContainer(string $id): ContainerStatus
    {
        $this->record('inspectContainer', ['id' => $id]);
        $this->maybeFail('inspectContainer');
        $this->mustHave($id);

        if (!isset($this->containers[$id])) {
            return $this->seededStatus($id) ?? ContainerStatus::running();
        }

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
    public function removeContainer(string $id, bool $removeAnonymousVolumes = false): void
    {
        $this->record('removeContainer', ['id' => $id, 'v' => $removeAnonymousVolumes]);
        $this->maybeFail('removeContainer');

        unset($this->containers[$id]);
    }

    #[Override]
    public function listContainers(): array
    {
        $this->record('listContainers', []);
        $this->maybeFail('listContainers');

        return $this->listed;
    }

    #[Override]
    public function stopContainer(string $id): void
    {
        $this->record('stopContainer', ['id' => $id]);
        $this->maybeFail('stopContainer');

        // The desired end state, reached: the container is no longer running.
        // A stop on an unknown or already-stopped container is tolerated by
        // the interface, so the script tolerates it too.
        foreach ($this->listed as $index => $container) {
            if ($container->id === $id) {
                $this->listed[$index] = ContainerSummary::fromApiSummary([
                    'Id' => $container->id,
                    'Names' => ['/'.$container->name],
                    'State' => 'exited',
                    'Created' => $container->createdAt,
                    'Labels' => $container->labels,
                ]);
            }
        }
    }

    #[Override]
    public function containerLogs(string $id, int $maxBytes): LogOutput
    {
        $this->record('containerLogs', ['id' => $id, 'maxBytes' => $maxBytes]);
        $this->maybeFail('containerLogs');
        $this->mustHave($id);

        if (!isset($this->containers[$id])) {
            // A seeded container: the next scripted logs, or nothing — the
            // same contract as a created container that was never scripted.
            $logs = $this->nextLogs ?? new LogOutput();
            $this->nextLogs = null;

            return $logs;
        }

        return $this->containers[$id]['logs'] ?? new LogOutput();
    }

    #[Override]
    public function createVolume(string $name, array $labels): void
    {
        $this->record('createVolume', ['name' => $name, 'labels' => $labels]);
        $this->maybeFail('createVolume');

        $this->volumes[$name] = $labels;
        $this->volumeLabels[$name] = $labels;
    }

    #[Override]
    public function networkExists(string $name): bool
    {
        $this->record('networkExists', ['name' => $name]);
        $this->maybeFail('networkExists');

        return isset($this->networks[$name]);
    }

    #[Override]
    public function createNetwork(string $name, array $labels): void
    {
        $this->record('createNetwork', ['name' => $name, 'labels' => $labels]);
        $this->maybeFail('createNetwork');

        $this->networks[$name] = $labels;
    }

    public function hasNetwork(string $name): bool
    {
        return isset($this->networks[$name]);
    }

    /**
     * @return array<string, string>
     */
    public function labelsOfNetwork(string $name): array
    {
        return $this->networks[$name] ?? [];
    }

    #[Override]
    public function volumeExists(string $name): bool
    {
        $this->record('volumeExists', ['name' => $name]);
        $this->maybeFail('volumeExists');

        return isset($this->volumes[$name]);
    }

    #[Override]
    public function pullImage(string $image, float $timeoutSeconds, int $maxOutputBytes): PullOutput
    {
        $this->record('pullImage', ['image' => $image, 'timeout' => $timeoutSeconds, 'maxOutputBytes' => $maxOutputBytes]);
        $this->maybeFail('pullImage');

        if ($this->pullScript['produces']) {
            $this->images[$image] = true;
        }

        return new PullOutput(
            $this->pullScript['output'],
            $this->pullScript['truncated'],
            $this->pullScript['timedOut'],
            $this->pullScript['failed'],
        );
    }

    #[Override]
    public function listVolumes(): array
    {
        $this->record('listVolumes', []);
        $this->maybeFail('listVolumes');

        $volumes = [];

        foreach ($this->volumeLabels as $name => $labels) {
            if (isset($this->volumes[$name])) {
                $volumes[] = VolumeSummary::fromApiVolume(['Name' => $name, 'Labels' => $labels]);
            }
        }

        return $volumes;
    }

    #[Override]
    public function removeVolume(string $name): void
    {
        $this->record('removeVolume', ['name' => $name]);
        $this->maybeFail('removeVolume');

        unset($this->volumes[$name], $this->volumeLabels[$name]);
    }

    #[Override]
    public function buildImage(array $query, mixed $context, float $timeoutSeconds, int $maxOutputBytes): BuildOutput
    {
        $this->record('buildImage', [
            'query' => $query,
            'timeout' => $timeoutSeconds,
            'maxOutputBytes' => $maxOutputBytes,
        ]);
        $this->maybeFail('buildImage');

        $tag = \is_string($query['t'] ?? null) ? $query['t'] : null;

        // Drains the context exactly as the real client does. No cap check
        // here: the cap belongs to ContextStream, which wraps the caller's
        // stream *before* it reaches this seam — so a cap trip raises from
        // there mid-iteration, which is the real path's behaviour and the
        // real path's exception.
        $source = \is_resource($context) ? self::chunks($context) : (is_iterable($context) ? $context : []);

        foreach ($source as $_) {
            // Discarded; the script answers with the scripted stream result.
        }

        $output = $this->buildScript['output'] ?? new BuildOutput('', false, false, false);

        // Tagging follows the script, not an assumption: `tagged: true` with
        // `timedOut: true` models the race the runner handles — the build
        // finished and tagged as the broker's clock cut the stream.
        if (null !== $tag && $this->buildScript['tagged']) {
            $this->images[$tag] = true;
        }

        return $output;
    }

    #[Override]
    public function imageExists(string $reference): bool
    {
        $this->record('imageExists', ['reference' => $reference]);
        $this->maybeFail('imageExists');

        return $this->images[$reference] ?? false;
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
        if (isset($this->containers[$id])) {
            return;
        }

        // Seeded containers (the janitor's and the stack's subjects) exist in
        // the listing but not in the create-lifecycle map. They are real
        // containers, so a call aimed at one must find it.
        foreach ($this->listed as $summary) {
            if ($summary->id === $id) {
                return;
            }
        }

        throw DockerRefused::because('no such container: '.$id);
    }

    /**
     * A seeded container's status, from the listing's own state — running
     * stays running, exited reports exit 0. (A command-path container's state
     * lives in the create map and takes the run script; this branch exists for
     * containers the broker did not just create.).
     */
    private function seededStatus(string $id): ?ContainerStatus
    {
        foreach ($this->listed as $summary) {
            if ($summary->id === $id) {
                return $summary->running ? ContainerStatus::running() : ContainerStatus::stopped(0);
            }
        }

        return null;
    }

    /**
     * @param resource $source
     *
     * @return iterable<string>
     */
    private static function chunks($source): iterable
    {
        while (!feof($source)) {
            $chunk = fread($source, 65_536);

            if (false === $chunk || '' === $chunk) {
                break;
            }

            yield $chunk;
        }
    }
}
