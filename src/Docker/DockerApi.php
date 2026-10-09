<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * The broker's one door to the Docker daemon — an interface so the door can be
 * a fake in the test suite.
 *
 * Every method is a deliberate subset of the Docker API: create a container
 * from a name and a payload somebody else assembled, start it, inspect it, kill
 * it, remove it, read its logs, and manage the workspace volume. There is no
 * method that takes a raw endpoint, a caller-chosen path, or a request body
 * from a caller — the shape of the door is part of the vocabulary, in the same
 * way the request objects are (DOCKER-ACCESS.md §2, §7).
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
     * `DELETE /containers/{id}?force=1`. Tolerates `404`: "gone" was the goal.
     */
    public function removeContainer(string $id): void;

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
