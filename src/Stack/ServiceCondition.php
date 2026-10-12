<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * When a service that another service depends on counts as satisfied.
 *
 * Three values, and each exists because its mechanism does:
 *
 * - `started` — the container was started. The weakest gate, and the right
 *   default for services that do not need to have *finished* anything.
 * - `completed` — it ran to an exit, and that exit was 0. What a migration
 *   step needs before the service that reads the migrated schema may start.
 * - `healthy` — its `health_cmd` reported healthy. The only condition that
 *   says "the database is accepting connections", because it can.
 *
 * Compose's `service_started`/`service_completed_successfully`/`service_healthy`
 * map onto these; nothing else does. The names stay short because they are
 * this API's own vocabulary, not a Compose dialect.
 */
enum ServiceCondition: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Healthy = 'healthy';
}
