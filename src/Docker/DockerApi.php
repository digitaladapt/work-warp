<?php

declare(strict_types=1);

namespace App\Docker;

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
}
