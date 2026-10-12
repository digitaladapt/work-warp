<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * A stack request, resolved into a start order.
 *
 * Built once, before anything is created, so that every refusal happens while
 * the stack is still only a value: a dependency naming a service that is not
 * in the request, a `healthy` condition pointing at a service with no health
 * command, a dependency cycle — each is a `422` from here rather than a
 * half-started stack discovered later. That "resolve first, act second" order
 * is the same one `ContainerSpec` and `BuildSpec` enforce at their altitudes:
 * what cannot be valid must not become real.
 *
 * The order is a stable topological sort: services with no dependencies keep
 * their request order, and a service is always visited after everything it
 * depends on. The runner walks it once, waiting on each step's conditions
 * immediately before it acts — which is correct because every dependency is
 * earlier in the order by construction.
 */
final class StackPlan
{
    /**
     * @param list<array{StackService, list<array{StackService, ServiceCondition}>}> $steps
     *                                                                                      each service, with its dependencies resolved to the services themselves
     */
    private function __construct(public readonly array $steps)
    {
    }

    public static function for(StackRequest $request): self
    {
        $services = $request->services;

        // Resolve and check every dependency before any ordering.
        $resolved = [];

        foreach ($services as $service) {
            $dependencies = [];

            foreach ($service->dependsOn as [$name, $condition]) {
                $dependency = $services[$name] ?? null;

                if (null === $dependency) {
                    throw InvalidStackRequest::because(\sprintf('service "%s" depends on "%s", which is not a service in this stack; dependencies are resolved against this request only', $service->name, $name));
                }

                if (ServiceCondition::Healthy === $condition && [] === $dependency->healthCmd) {
                    // Refused rather than waited-for-forever: "healthy" is a
                    // promise about a health check, and a service without a
                    // health_cmd can never make it true.
                    throw InvalidStackRequest::because(\sprintf('service "%s" needs "%s" healthy, but "%s" declares no health_cmd — add one, or use the "started" condition', $service->name, $name, $name));
                }

                if ($dependency === $service) {
                    throw InvalidStackRequest::because(\sprintf('service "%s" depends on itself', $service->name));
                }

                $dependencies[] = [$dependency, $condition];
            }

            $resolved[$service->name] = [$service, $dependencies];
        }

        return new self(self::order($resolved));
    }

    /**
     * Stable Kahn: repeatedly take the first not-yet-placed service whose
     * dependencies are all placed. A stable order matters for readability —
     * two `up`s of the same request produce the same sequence — and the
     * leftover set at the end is exactly the cycle, reported by name.
     *
     * @param array<string, array{StackService, list<array{StackService, ServiceCondition}>}> $resolved
     *
     * @return list<array{StackService, list<array{StackService, ServiceCondition}>}>
     */
    private static function order(array $resolved): array
    {
        $placed = [];
        $remaining = $resolved;

        while ([] !== $remaining) {
            $progressed = false;

            foreach ($remaining as $name => [$service, $dependencies]) {
                foreach ($dependencies as [$dependency]) {
                    if (!\array_key_exists($dependency->name, $placed)) {
                        continue 2;
                    }
                }

                $placed[$name] = [$service, $dependencies];
                unset($remaining[$name]);
                $progressed = true;
            }

            if (!$progressed) {
                throw InvalidStackRequest::because(\sprintf('dependency cycle among: %s — a stack is a graph, and this one does not order', implode(', ', array_keys($remaining))));
            }
        }

        return array_values($placed);
    }
}
