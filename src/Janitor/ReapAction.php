<?php

declare(strict_types=1);

namespace App\Janitor;

/**
 * What a sweep did to a container.
 *
 * Two values, because the sweep takes exactly one action per container per
 * pass and PLAN.md §8's table has exactly two rows: a container that outran
 * its TTL while running is *stopped*; one that is already down and forgotten
 * is *removed*. Keeping this an enum rather than a string means the report,
 * the console output and any future client share one vocabulary.
 *
 * A stopped container is not removed in the same pass, deliberately: the next
 * sweep finds it not-running and removes it (it is still past its TTL), and
 * one action per pass keeps every report line unambiguous about what happened
 * and when.
 */
enum ReapAction: string
{
    case Stopped = 'stopped';
    case Removed = 'removed';
}
