<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\DockerApi;
use App\Docker\Labels;

/**
 * Every container a session owns, in one answer.
 *
 * One listing, filtered by the `ww.session` label — the same label the stack
 * runner's `down` and `logs` filter by, and the reason this class exists
 * beside it is that three callers now ask the same question (`ps`, the
 * session view, and the teardown) and a filter written three times is a
 * filter that drifts twice.
 *
 * The filter is *also* the boundary: `listContainers()` asks the daemon only
 * for containers carrying `ww.created-by=work-warp` (the client builds that
 * filter itself and no caller can widen it), so a neighbour's container can
 * never appear here whatever it is labelled. Session membership is then
 * decided by the label, never by the name.
 */
final class SessionContainers
{
    public function __construct(
        private readonly DockerApi $docker,
    ) {
    }

    /**
     * @return list<SessionContainer> ordered by container name, so two calls agree
     */
    public function forSession(SessionName $session): array
    {
        $containers = [];

        foreach ($this->docker->listContainers() as $container) {
            if (($container->labels[Labels::LABEL_SESSION] ?? null) !== $session->value) {
                continue;
            }

            $containers[] = SessionContainer::fromSummary($container);
        }

        usort(
            $containers,
            static fn (SessionContainer $a, SessionContainer $b): int => strcmp($a->name, $b->name),
        );

        return $containers;
    }
}
