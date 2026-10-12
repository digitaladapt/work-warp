<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * Where a mount lands inside the service's container.
 *
 * An absolute path, and the only absolutes that are *sane* for a service are
 * the ones the image already expects — `/app/data`, `/var/lib/postgresql/data`
 * — so the path is checked, not rewritten: it must start with `/`, must not
 * contain `..` segments, and must not be the root itself. Nothing here is
 * resolved against the host, because nothing here is a host path: the source
 * half of the mount is a `StackVolumeName`, and this is only where it appears.
 *
 * (`/var/run/docker.sock` is not refused *here* — refusing it by string
 * would be a filter, and filters are the thing this design does not trust.
 * It is unexpressible at the layer above: the source is a named volume this
 * broker created, so writing that socket's path as a target mounts a volume
 * *at* that path, which is not the socket.)
 */
final class StackContainerPath
{
    private const string PATTERN = '#^/[A-Za-z0-9._/-]*\z#';

    private function __construct(public readonly string $value)
    {
    }

    public static function from(string $path): self
    {
        if (1 !== preg_match(self::PATTERN, $path)) {
            throw InvalidStackRequest::because(\sprintf('mount targets must be absolute paths of letters, digits, dots, dashes, underscores and slashes, got "%s"', $path));
        }

        if ('/' === $path) {
            throw InvalidStackRequest::because('a mount target must not be the container root, got "/"');
        }

        foreach (explode('/', $path) as $segment) {
            if ('..' === $segment) {
                throw InvalidStackRequest::because(\sprintf('mount targets must not contain "..", got "%s"', $path));
            }
        }

        return new self($path);
    }
}
