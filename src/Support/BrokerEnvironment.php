<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The environment names the broker owns and sets itself wherever it captures
 * output — PLAN.md §5.1's direct fix for the failure that started this project:
 * a process that believes it has a human terminal opens a pager and waits for
 * keystrokes that never come.
 *
 * One home for the pair of facts the vocabularies share: the names themselves,
 * and that a caller may not set them (glibc's `getenv` returns the *first*
 * match, so a caller's earlier copy would beat the broker's later one).
 */
final class BrokerEnvironment
{
    /**
     * @var array<string, string>
     */
    public const array OWNED = [
        'PAGER' => 'cat',
        'GIT_PAGER' => 'cat',
        'TERM' => 'dumb',
        'NO_COLOR' => '1',
        'CI' => '1',
    ];
}
