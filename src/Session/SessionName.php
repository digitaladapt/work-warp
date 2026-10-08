<?php

declare(strict_types=1);

namespace App\Session;

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
}
