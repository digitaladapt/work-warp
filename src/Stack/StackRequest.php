<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * A stack, as typed input: what to do, and — for `up` — what the stack is.
 *
 * The request is where the design's "unexpressible beats filtered" argument
 * moves up one altitude. A compose file can say `privileged: true`,
 * `network_mode: host`, `volumes: [/:/host]`, `devices:`, `cap_add:`. This
 * object *cannot*, because none of those are fields in `StackService` or in
 * anything it references — and the broker renders the stack itself rather than
 * handing the caller's text to a compose implementation, so there is no
 * intermediate format in which the words could exist.
 *
 * What `up` carries:
 *
 * - `services` — required, a map of name → `StackService`, capped at
 *   `StackService::MAX_SERVICES`.
 * - `timeout` — the whole-up budget, 1..`MAX_TIMEOUT`, default
 *   `DEFAULT_TIMEOUT`. The same bounds as everything else in this API: one
 *   timeout vocabulary, not three.
 *
 * `down` and `logs` take no services: they address the stack the session
 * already has, and a request that carried a *different* stack body would be
 * describing something that is not there. A `services` key on those actions is
 * ignored the way unknown keys are everywhere else in this codebase — allowed,
 * unread — while the fields that *are* read are validated strictly. (Ignoring
 * rather than refusing is deliberate: a client that sends a superset of the
 * shape should not be told its request is malformed, and nothing is created
 * from the ignored part.)
 */
final class StackRequest
{
    public const int DEFAULT_TIMEOUT = 1800;
    public const int MAX_TIMEOUT = 14400;

    /**
     * @param array<string, StackService> $services
     */
    private function __construct(
        public readonly StackAction $action,
        public readonly array $services,
        public readonly int $timeout,
        public readonly bool $keepVolumes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $action = self::actionFrom($data['action'] ?? null);
        $services = StackAction::Up === $action ? self::servicesFrom($data['services'] ?? null) : [];

        return new self(
            $action,
            $services,
            self::timeoutFrom($data['timeout'] ?? null),
            self::keepVolumesFrom($data['keep_volumes'] ?? null),
        );
    }

    private static function actionFrom(mixed $value): StackAction
    {
        if (!\is_string($value)) {
            throw InvalidStackRequest::because(\sprintf('action is required and must be one of %s, got %s', implode(' | ', array_column(StackAction::cases(), 'value')), get_debug_type($value)));
        }

        $action = StackAction::tryFrom($value);

        if (null === $action) {
            throw InvalidStackRequest::because(\sprintf('action must be one of %s, got "%s"', implode(' | ', array_column(StackAction::cases(), 'value')), $value));
        }

        return $action;
    }

    /**
     * @return array<string, StackService>
     */
    private static function servicesFrom(mixed $value): array
    {
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            throw InvalidStackRequest::because('services is required for `up` and must be an object of name → service, e.g. {"web": {"image": "nginx:alpine"}}');
        }

        if (\count($value) > StackService::MAX_SERVICES) {
            throw InvalidStackRequest::because(\sprintf('a stack holds at most %d services, got %d', StackService::MAX_SERVICES, \count($value)));
        }

        $services = [];

        foreach ($value as $name => $service) {
            if (!\is_string($name)) {
                throw InvalidStackRequest::because(\sprintf('service names must be strings, got %s', get_debug_type($name)));
            }

            if (!\is_array($service)) {
                throw InvalidStackRequest::because(\sprintf('service "%s" must be an object, got %s', $name, get_debug_type($service)));
            }

            $services[$name] = StackService::from($name, $service);
        }

        return $services;
    }

    private static function timeoutFrom(mixed $value): int
    {
        if (null === $value) {
            return self::DEFAULT_TIMEOUT;
        }

        if (\is_int($value)) {
            $timeout = $value;
        } elseif (\is_string($value) && 1 === preg_match('/^\d+\z/', $value)) {
            $timeout = (int) $value;
        } else {
            throw InvalidStackRequest::because(\sprintf('timeout must be a whole number of seconds, got %s', get_debug_type($value)));
        }

        if ($timeout < 1 || $timeout > self::MAX_TIMEOUT) {
            throw InvalidStackRequest::because(\sprintf('timeout must be between 1 and %d seconds, got %d', self::MAX_TIMEOUT, $timeout));
        }

        return $timeout;
    }

    /**
     * `down`'s one option: whether the stack's named volumes survive the
     * teardown.
     *
     * Deliberately the opposite default from `docker compose down`, and the
     * reason is the harness itself: this endpoint is a test teardown, and a
     * teardown that leaves a database behind is how two runs contaminate each
     * other. `keep_volumes: true` is the inspecting case ("what did the run
     * leave in there?"), and it is one field away. Ignored by `up`/`logs`.
     */
    private static function keepVolumesFrom(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }

        if (!\is_bool($value)) {
            throw InvalidStackRequest::because(\sprintf('keep_volumes must be a boolean, got %s', get_debug_type($value)));
        }

        return $value;
    }
}
