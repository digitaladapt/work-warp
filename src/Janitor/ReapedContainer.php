<?php

declare(strict_types=1);

namespace App\Janitor;

/**
 * One container a sweep acted on.
 *
 * `ageSeconds` and `ttlSeconds` are carried rather than recomputed because
 * the question an operator asks when a container died — "why did you kill
 * this?" — has exactly one useful answer: the age it had against the TTL it
 * carried. Both are in the report and in the session's ledger line.
 */
final class ReapedContainer
{
    public function __construct(
        public readonly string $container,
        public readonly ?string $session,
        public readonly ReapAction $action,
        public readonly int $ageSeconds,
        public readonly int $ttlSeconds,
    ) {
    }

    /**
     * @return array{container: string, session: string|null, action: string, age_seconds: int, ttl_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'container' => $this->container,
            'session' => $this->session,
            'action' => $this->action->value,
            'age_seconds' => $this->ageSeconds,
            'ttl_seconds' => $this->ttlSeconds,
        ];
    }
}
