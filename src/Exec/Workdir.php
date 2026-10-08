<?php

declare(strict_types=1);

namespace App\Exec;

/**
 * A working directory *inside* the session's workspace volume.
 *
 * Relative to `/workspace` and structurally unable to leave it: an absolute
 * path or a `..` segment is refused outright. There is no normalisation here on
 * purpose — see InvalidExecRequest.
 */
final class Workdir
{
    /**
     * The workspace root itself, which is what a command gets when it does not
     * ask for anything.
     */
    public const DEFAULT = '.';

    private function __construct(public readonly string $path)
    {
    }

    public static function from(string $path): self
    {
        $trimmed = trim($path);

        if ('' === $trimmed) {
            return new self(self::DEFAULT);
        }

        if (str_contains($trimmed, "\0")) {
            throw InvalidExecRequest::because('workdir must not contain a null byte');
        }

        if (str_starts_with($trimmed, '/')) {
            throw InvalidExecRequest::because(\sprintf('workdir must be relative to the workspace, got the absolute path "%s"', $trimmed));
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ('..' === $segment) {
                throw InvalidExecRequest::because(\sprintf('workdir must not escape the workspace, got "%s"', $trimmed));
            }
        }

        return new self($trimmed);
    }

    public function absolute(): string
    {
        return self::DEFAULT === $this->path ? '/workspace' : '/workspace/'.$this->path;
    }
}
