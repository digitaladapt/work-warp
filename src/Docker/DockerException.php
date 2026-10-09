<?php

declare(strict_types=1);

namespace App\Docker;

use RuntimeException;

/**
 * A Docker conversation that did not go as planned.
 *
 * Two shapes of failure, deliberately distinguished, because they are the two
 * facts an operator needs: the daemon could not be reached at all
 * (DockerUnavailable — configuration, the proxy, or a dead daemon), or it was
 * reached and said no (DockerRefused — and it usually says why). The HTTP layer
 * maps them to 503 and 502 respectively, so the distinction survives all the
 * way to the caller.
 */
abstract class DockerException extends RuntimeException
{
}
