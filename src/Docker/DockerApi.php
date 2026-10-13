<?php

declare(strict_types=1);

namespace App\Docker;

use App\Build\BuildContextTooLarge;
use App\Build\BuildOutput;

/**
 * The broker's one door to the Docker daemon — an interface so the door can be
 * a fake in the test suite.
 *
 * Every method is a deliberate subset of the Docker API: create a container
 * from a name and a payload somebody else assembled, start it, inspect it, kill
 * it, remove it, read its logs, and manage the workspace volume and the
 * session's network. There is no method that takes a raw endpoint, a
 * caller-chosen path, or a request body from a caller — the shape of the door
 * is part of the vocabulary, in the same way the request objects are
 * (DOCKER-ACCESS.md §2, §7).
 *
 * The interface exists for one practical reason on top of the principled ones:
 * the quality gates must run without a daemon. CONTRIBUTING.md is explicit that
 * tests assert what the broker *builds*, never what it *reaches* — so the suite
 * swaps in a scripted double, and the real implementation is exercised against
 * MockHttpClient, which records requests exactly.
 */
interface DockerApi
{
    /**
     * `POST /containers/create`, with the name as the one query parameter and
     * the payload assembled by ContainerSpec — never by a caller.
     *
     * @param array<string, mixed> $payload
     *
     * @return string the container's full id, as the daemon reports it
     */
    public function createContainer(string $name, array $payload): string;

    /**
     * `POST /containers/{id}/start`.
     *
     * Tolerant of Docker's `304 Container already started` on purpose: the
     * desired end state is "running", and it was reached. A broker restart must
     * be able to adopt a container it had already started.
     */
    public function startContainer(string $id): void;

    /**
     * `GET /containers/{id}/json`, reduced to the two facts the broker acts on:
     * whether the container still runs, and — once it does not — its exit code.
     */
    public function inspectContainer(string $id): ContainerStatus;

    /**
     * `POST /containers/{id}/kill`. Tolerates `404` and `409`: killing a
     * container that has already stopped reaches the desired end state, so it
     * is not an error.
     */
    public function killContainer(string $id): void;

    /**
     * `DELETE /containers/{id}?force=1`, optionally with `v=1` to remove the
     * container's anonymous volumes along with it.
     *
     * `force` stays because killing on the way out is right for the broker's
     * own containers. `v` is what the janitor adds: it is the last hand on a
     * container that outlived its consumer, and PLAN.md §8 is explicit that
     * anonymous volumes and dangling images — not running containers — are
     * what fills a disk. Tolerates `404`: "gone" was the goal.
     */
    public function removeContainer(string $id, bool $removeAnonymousVolumes = false): void;

    /**
     * `GET /containers/{id}/logs`, demultiplexed into stdout and stderr and
     * capped at `$maxBytes` per stream.
     *
     * Reading stops as soon as either stream reaches the cap, and the result
     * says so: draining a runaway command's output to discard most of it is a
     * memory leak with extra steps.
     */
    public function containerLogs(string $id, int $maxBytes): LogOutput;

    /**
     * `GET /containers/json?all=1`, filtered to the broker's own containers and
     * reduced to what a sweep acts on.
     *
     * The filter is built here from `Labels` and never passed in: the one
     * cleanup path this broker has must not be able to aim itself at a
     * neighbour's container, and a caller that could choose the filter could
     * do exactly that (SECURITY.md; `ww-run reap`'s ownership rule, which this
     * method is the server-side form of).
     *
     * @return list<ContainerSummary>
     */
    public function listContainers(): array;

    /**
     * `POST /containers/{id}/stop`.
     *
     * No `t` is passed, deliberately: honouring the stop-timeout means the
     * container's own `StopTimeout` (baked in at create by `ContainerSpec`),
     * and the daemon already implements the sequence PLAN.md §8 asks for —
     * SIGTERM, wait, then SIGKILL. Tolerates `304` (already stopped) and `404`
     * (already gone): both are the desired end state, and a sweep that raced
     * another cleaner must not read that as a failure.
     */
    public function stopContainer(string $id): void;

    /**
     * `GET /networks/{id}` as a question, like `volumeExists`: asked before
     * anything is created, so a session's network is made lazily and exactly
     * once.
     */
    public function networkExists(string $name): bool;

    /**
     * `POST /networks/create`. The name is checked by the daemon as the
     * network's own name; creating one that already exists is a conflict the
     * caller resolves by asking first.
     *
     * @param array<string, string> $labels
     */
    public function createNetwork(string $name, array $labels): void;

    /**
     * `DELETE /networks/{name}`. Tolerates `404`: "gone" was the goal, and a
     * session teardown that raced another cleaner must not read that as a
     * failure.
     *
     * A network with attached endpoints is refused by the daemon, which is a
     * real refusal and leaves as one — the caller's order is wrong (containers
     * first, network after), and this client does not guess at a retry.
     *
     * Through the socket proxy this forwards today: v0.5.0 does not gate on
     * method, and `NETWORKS: 1` covers the section (measured 2026-10-07).
     */
    public function removeNetwork(string $name): void;

    /**
     * `POST /volumes/create`. Creating a volume that already exists is a no-op
     * at the daemon, and is not an error here either.
     *
     * @param array<string, string> $labels
     */
    public function createVolume(string $name, array $labels): void;

    /**
     * `GET /volumes/{name}` as a question.
     *
     * The broker asks before it mounts: the workspace volume is what a session
     * *is* today, so "no volume" is a missing session — and finding that out
     * from a failed container create would misreport it as a daemon problem.
     */
    public function volumeExists(string $name): bool;

    /**
     * `POST /build`, with the query assembled by `BuildSpec` and the context
     * streamed as the request body.
     *
     * The stream is read as it arrives and only the lines that carry output,
     * an image tag or an error are kept, bounded by `$maxOutputBytes` for the
     * same reason the log reader is bounded. A build that runs and fails is a
     * *result* — the daemon's own diagnosis is in `output` — not an
     * exception; the exceptions mean the broker could not ask
     * (DockerUnavailable), the daemon said no (DockerRefused), or the caller's
     * context ran past its cap (`BuildContextTooLarge`, which passes through
     * here unchanged so the controller can answer 413 rather than 5xx).
     *
     * The budget is the HTTP call's own: `$timeoutSeconds` bounds the request,
     * and the daemon documents that a build whose client disconnects is
     * cancelled — which is the only clock that can exist for a build, because
     * the build's containers belong to the daemon and there is nothing here
     * to kill.
     *
     * @param array<string, string>            $query   assembled by BuildSpec, never by a caller
     * @param resource|iterable<string>|string $context the build context, already capped by ContextStream
     *
     * @throws BuildContextTooLarge when the context stream raises its cap mid-flight
     */
    public function buildImage(array $query, mixed $context, float $timeoutSeconds, int $maxOutputBytes): BuildOutput;

    /**
     * `GET /images/{name}/json` as a question: did the build produce the image
     * it said it would?
     *
     * The runner asks this after a stream that ended without a timeout, and it
     * is the *authoritative* success signal — the alternative is believing a
     * text line ("Successfully tagged …") that the broker did not write and
     * cannot vouch for. The daemon answers 200 for a present image and 404 for
     * an absent one; anything else is a refusal, and a lookup the daemon
     * refused must never be read as "no" (the same rule as `volumeExists`).
     *
     * @phpstan-impure deliberately: the answer *changes* — a pull between two
     *                  calls is exactly why callers ask twice (the stack
     *                  runner asks before a pull and again after it, and the
     *                  second answer must not be folded into the first by
     *                  static analysis).
     */
    public function imageExists(string $reference): bool;

    /**
     * `POST /images/create` — the registry pull, with the timeout as both the
     * idle and the absolute budget (the same clock shape as `buildImage`, for
     * the same reason: a pull can stall, and the daemon documents that a
     * client that disconnects cancels the operation).
     *
     * The stream is read and only the `{"error":…}` lines are kept, bounded by
     * `$maxOutputBytes`; the *outcome* is never read from the text — callers
     * confirm it with `imageExists`, and a pull that reported success but
     * produced nothing is a failure like any other.
     *
     * Through the socket proxy this is `POST /images/create`, which the
     * deployed policy already forwards (`IMAGES: 1` covers the section,
     * `POST: 1` the method) — measured against the live proxy on 2026-10-07,
     * where a pull succeeded through it. No deployment change: the stack
     * harness pulls with the permissions the broker already has.
     */
    public function pullImage(string $image, float $timeoutSeconds, int $maxOutputBytes): PullOutput;

    /**
     * `GET /volumes`, filtered to the broker's own volumes and reduced to
     * what a cleanup acts on.
     *
     * Like `listContainers`, the filter is built here from `Labels` and never
     * passed in: the stack teardown is the second cleanup path this broker
     * has, and it must not be aimable at a neighbour's volume either.
     *
     * @return list<VolumeSummary>
     */
    public function listVolumes(): array;

    /**
     * `DELETE /volumes/{name}`. Tolerates `404`: "gone" was the goal.
     *
     * A volume still attached to a running container is refused by the daemon,
     * which is a real refusal and leaves as one — the caller's order is wrong,
     * and this client does not guess at a retry.
     */
    public function removeVolume(string $name): void;
}
