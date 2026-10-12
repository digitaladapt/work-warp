<?php

declare(strict_types=1);

namespace App\Docker;

use App\Session\SessionName;
use App\Stack\StackService;

/**
 * The `ww.` label vocabulary, in one place, for everything the broker names.
 *
 * Ownership is decided by labels and never by a resource's typed name
 * (DOCKER-ACCESS.md §4), so these constants are load-bearing: they are what the
 * janitor and every ownership check will read. Until now they only had one
 * user — the container payload — and lived inside ContainerSpec; the workspace
 * volume is the second user, and a volume labelled `ww.session` from a class
 * called ContainerSpec reads wrong.
 *
 * A value is stamped by the broker and never by a caller, which is why the
 * pairs sit together: `ww.created-by` is only ever `work-warp`, and the
 * `ww.kind` values are the two kinds of resource a session owns today.
 */
final class Labels
{
    public const string CREATED_BY = 'work-warp';

    public const string LABEL_CREATED_BY = 'ww.created-by';
    public const string LABEL_SESSION = 'ww.session';
    public const string LABEL_KIND = 'ww.kind';
    public const string LABEL_TTL = 'ww.ttl';

    /** A command container: ephemeral, one per command. */
    public const string KIND_CMD = 'cmd';

    /** A workspace volume: the persistent half of a session. */
    public const string KIND_WORKSPACE = 'workspace';

    /** A session network: created lazily, the first time a command asks to reach one. */
    public const string KIND_NETWORK = 'network';

    /**
     * An image produced by a session's scratch build path: disposable,
     * scoped to one session by its tag (`ww-<session>-<tag>`), and labelled
     * so a future image-pruning sweep can tell whose it is.
     */
    public const string KIND_BUILD = 'build';

    /**
     * A stack service container: long-running, brought up by `POST …/stack`,
     * and — unlike a command — alive for as long as the stack is.
     */
    public const string KIND_STACK = 'stack';

    /**
     * A stack's service data volume (`ww-stk-<session>-<name>`): what the
     * service persists, and what `down` removes when it is not asked to keep
     * volumes.
     */
    public const string KIND_STACK_VOLUME = 'stack-volume';

    /**
     * Which service in the stack a container or volume belongs to.
     *
     * The service's own name, from `StackService` — never a container name or
     * an id — so `down`, `logs` and a human reading `docker ps` all agree
     * about what they are looking at.
     */
    public const string LABEL_SERVICE = 'ww.service';

    /**
     * The labels every workspace volume carries.
     *
     * No TTL: a volume is the thing a session *is*, and nothing sweeps it on a
     * clock — `DELETE /v1/sessions/{name}` is the only thing that removes one,
     * deliberately.
     *
     * @return array<string, string>
     */
    public static function workspace(SessionName $session): array
    {
        return [
            self::LABEL_CREATED_BY => self::CREATED_BY,
            self::LABEL_SESSION => $session->value,
            self::LABEL_KIND => self::KIND_WORKSPACE,
        ];
    }

    /**
     * The labels a session's network carries, when a command first asks to
     * reach it. Same reasoning as the volume: ownership by label, never by the
     * name someone could have typed.
     *
     * @return array<string, string>
     */
    public static function network(SessionName $session): array
    {
        return [
            self::LABEL_CREATED_BY => self::CREATED_BY,
            self::LABEL_SESSION => $session->value,
            self::LABEL_KIND => self::KIND_NETWORK,
        ];
    }

    /**
     * The labels a built image carries. Same reasoning as the volume and the
     * network: whose it is must be a label, not a guess made from the tag.
     *
     * @return array<string, string>
     */
    public static function buildImage(SessionName $session): array
    {
        return [
            self::LABEL_CREATED_BY => self::CREATED_BY,
            self::LABEL_SESSION => $session->value,
            self::LABEL_KIND => self::KIND_BUILD,
        ];
    }

    /**
     * The labels a stack service container carries.
     *
     * `ww.ttl` is the stack request's timeout plus the grace — the same rule
     * as a command's: a stack abandoned while up is eventually swept, and a
     * stack the caller is still using is not (its containers are re-created
     * by every `up`, which moves the clock).
     *
     * @return array<string, string>
     */
    public static function stack(SessionName $session, StackService $service, int $ttl): array
    {
        return [
            self::LABEL_CREATED_BY => self::CREATED_BY,
            self::LABEL_SESSION => $session->value,
            self::LABEL_KIND => self::KIND_STACK,
            self::LABEL_SERVICE => $service->name,
            self::LABEL_TTL => (string) $ttl,
        ];
    }

    /**
     * The labels a stack service volume carries. No TTL: like the workspace,
     * a volume is removed by an explicit action (`down`), never by a clock —
     * the janitor removes anonymous volumes with their container, but a named
     * stack volume outlives any single container on purpose.
     *
     * No `ww.service` either: a volume is named after the *mount* the caller
     * declared (`ww-stk-<session>-<name>`), and two services that share a
     * mount share the volume — labelling it with one of them would be wrong
     * for the other. The volume's name is the fact; the labels say whose
     * session it is.
     *
     * @return array<string, string>
     */
    public static function stackVolume(SessionName $session): array
    {
        return [
            self::LABEL_CREATED_BY => self::CREATED_BY,
            self::LABEL_SESSION => $session->value,
            self::LABEL_KIND => self::KIND_STACK_VOLUME,
        ];
    }
}
