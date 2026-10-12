<?php

declare(strict_types=1);

namespace App\Session;

use App\Build\BuildTag;

/**
 * A session name, and the only source of the names derived from it.
 *
 * Everything a session owns is named here and nowhere else — the volume, the
 * network, the containers — so the `ww-` prefix is generated rather than
 * supplied and a caller cannot aim at a resource by typing its name
 * (DOCKER-ACCESS.md §5, §6).
 *
 * Ownership is still decided by labels, never by these strings. The names exist
 * so that `docker ps` is readable at 2am and so that two sessions cannot
 * collide; they are not the security boundary.
 */
final class SessionName
{
    /**
     * Lowercase, no leading dash, 31 characters at most.
     *
     * Anchored with `\z` rather than `$` on purpose: in PCRE, `$` also matches
     * immediately before a trailing newline, so `/…$/` accepts "refactor\n" and
     * the derived resource names acquire a newline. `\z` is the true end of
     * the subject and is the only anchor that means what it looks like.
     */
    private const string PATTERN = '/^[a-z0-9][a-z0-9-]{0,30}\z/';

    private function __construct(public readonly string $value)
    {
    }

    public static function from(string $name): self
    {
        if (1 !== preg_match(self::PATTERN, $name)) {
            throw InvalidSessionName::because(\sprintf('session names must match %s (lowercase letters, digits and dashes, at most 31 characters), got "%s"', self::PATTERN, $name));
        }

        return new self($name);
    }

    /**
     * The persistent volume mounted at /workspace.
     */
    public function workspaceVolume(): string
    {
        return 'ww-ws-'.$this->value;
    }

    /**
     * The session's own network, created by the broker and named only here.
     */
    public function network(): string
    {
        return 'ww-net-'.$this->value;
    }

    /**
     * A container belonging to this session.
     */
    public function containerName(string $id): string
    {
        return 'ww-'.$this->value.'-'.$id;
    }

    /**
     * The repository a scratch build lands in: `ww-<session>-<tag>`.
     *
     * Scoped to one session by construction — the caller supplies only the
     * tag, and the session prefix is this method's business — so a build can
     * neither overwrite another session's image nor collide with the shared
     * base (`digitaladapt/work-warp:*`). Disposable by convention: nothing
     * pushes it, and a rebuild of the same tag replaces it.
     */
    public function buildImageRef(BuildTag $tag): string
    {
        return 'ww-'.$this->value.'-'.$tag->value;
    }

    /**
     * A stack service container: `ww-<session>-<service>-1`.
     *
     * The `-1` is Compose's numbering for a single-instance service, kept
     * because it is the naming everyone already reads fluently — and because
     * it cannot collide with a command container (`ww-<session>-<10 hex>`),
     * whose suffix has no dash-number shape.
     *
     * Deterministic rather than random, deliberately: two `up`s racing in one
     * session cannot both hold the same service, because the second create is
     * a name conflict rather than a second container.
     */
    public function stackContainerName(string $service): string
    {
        return 'ww-'.$this->value.'-'.$service.'-1';
    }

    /**
     * A stack service data volume: `ww-stk-<session>-<name>`.
     *
     * `stk` in the middle so a glance at `docker volume ls` separates a
     * stack's data from a workspace (`ww-ws-…`) — and the name is generated
     * here rather than supplied, so a caller cannot aim at a volume by typing
     * its name. Ownership is still decided by labels.
     */
    public function stackVolume(string $name): string
    {
        return 'ww-stk-'.$this->value.'-'.$name;
    }
}
