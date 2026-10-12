<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * What `up` did.
 *
 * `failure` is the honest half: a stack that could not come up — a dependency
 * that exited non-zero, a service that never became healthy, a container the
 * daemon refused — is a *result*, not a 5xx, for the same reason a failed
 * build is: the broker worked, and the difference between "the stack did not
 * come up" and "the broker broke" is precisely the difference the caller
 * needs. Services that were brought up before the failure stay up; `down` is
 * how the caller gets back to nothing, and the body says which is which.
 *
 * `timedOut` is about the *budget* — the request's timeout covering the whole
 * bring-up and its waits — while a `failure` can happen at any point without
 * the budget being spent.
 */
final class UpResult
{
    /**
     * @param list<StackServiceState>                     $services
     * @param array{service: string, reason: string}|null $failure
     */
    public function __construct(
        public readonly array $services,
        public readonly ?array $failure,
        public readonly bool $timedOut,
    ) {
    }
}
