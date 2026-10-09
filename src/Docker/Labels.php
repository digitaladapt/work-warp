<?php

declare(strict_types=1);

namespace App\Docker;

use App\Session\SessionName;

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
}
