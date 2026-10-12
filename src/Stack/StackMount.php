<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * One mount in a stack service: a named volume of this stack, at one path.
 *
 * The pair is the entire mount vocabulary — see `StackVolumeName` for what
 * cannot be said (a host path, the socket, a `bind` type). `readOnly` exists
 * because a service that only reads a volume should say so; it is one boolean
 * and it narrows, so it earns its field.
 */
final class StackMount
{
    private function __construct(
        public readonly StackVolumeName $source,
        public readonly StackContainerPath $target,
        public readonly bool $readOnly,
    ) {
    }

    public static function from(StackVolumeName $source, string $target, bool $readOnly): self
    {
        return new self($source, StackContainerPath::from($target), $readOnly);
    }
}
