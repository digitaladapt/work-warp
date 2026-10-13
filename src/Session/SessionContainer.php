<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\ContainerSummary;
use App\Docker\Labels;

/**
 * One container a session owns, as the session's own endpoints describe it.
 *
 * The reduction is the same discipline as `ContainerSummary`'s one layer down:
 * a fixed field set a caller can depend on, read from the labels the broker
 * stamped — never inferred from the container's name, which is the
 * vocabulary's rule everywhere.
 *
 * `kind` is the `ww.kind` label (`cmd` or `stack` for containers), and
 * `service` is present exactly when the kind is `stack`: it is the label the
 * stack's own `down`/`logs` key on, so every surface agrees about what a
 * stack member is called.
 */
final class SessionContainer
{
    private function __construct(
        public readonly string $name,
        public readonly string $id,
        public readonly string $kind,
        public readonly bool $running,
        public readonly ?string $service,
        public readonly int $createdAt,
    ) {
    }

    public static function fromSummary(ContainerSummary $container): self
    {
        return new self(
            $container->name,
            $container->id,
            $container->labels[Labels::LABEL_KIND] ?? 'unknown',
            $container->running,
            $container->labels[Labels::LABEL_SERVICE] ?? null,
            $container->createdAt,
        );
    }

    /**
     * `created_at` is seconds since the epoch — the daemon's own unit and the
     * same one `ww.ttl` uses, so a caller computes an age without a date
     * library. `id` stays full: a caller that wants the short form truncates.
     *
     * @return array{name: string, id: string, kind: string, running: bool, service: string|null, created_at: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'id' => $this->id,
            'kind' => $this->kind,
            'running' => $this->running,
            'service' => $this->service,
            'created_at' => $this->createdAt,
        ];
    }
}
