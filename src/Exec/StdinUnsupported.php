<?php

declare(strict_types=1);

namespace App\Exec;

use RuntimeException;

/**
 * `stdin` was asked for, and this broker cannot deliver it yet.
 *
 * Refused rather than ignored, deliberately. Delivering stdin means hijacking
 * the daemon connection (Docker's `attach` upgrades the HTTP request and then
 * talks raw over the socket), and the broker's HTTP client does not do that.
 * A broker that quietly ran the command without its input would be worse than
 * one that says it cannot: the first looks like a command that read an empty
 * file, the second is a fact the caller can act on.
 *
 * The field stays in the vocabulary — it is validated and capped like the
 * rest — so this refusal is a statement about the build, not about the shape
 * of the API. It lands with connection-hijack support, and until then the
 * workaround is a file in the workspace.
 */
final class StdinUnsupported extends RuntimeException
{
    public static function because(): self
    {
        return new self(
            'stdin is not deliverable yet: it needs the daemon\'s connection-hijacking attach path, which the broker does not implement. '
            .'It is refused rather than silently dropped — write the input to a file in the workspace and read it from the command instead.',
        );
    }
}
