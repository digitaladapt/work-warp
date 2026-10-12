<?php

declare(strict_types=1);

namespace App\Stack;

use App\Docker\DockerException;

/**
 * A service's image could not be made present.
 *
 * Distinct from `DockerRefused` because the message is assembled from two
 * sources — the pull's streamed error and the image lookup that confirmed the
 * absence — and the caller reading it should see that whole sentence rather
 * than the daemon's fragment of it. It is a `DockerException`, so the runner's
 * per-service catch treats it like any other bring-up failure: recorded as the
 * step's `failure`, not thrown past the endpoint.
 */
final class StackPullFailed extends DockerException
{
}
