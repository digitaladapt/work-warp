<?php

declare(strict_types=1);

namespace App\Build;

use App\Docker\BuildSpec;
use App\Docker\DockerApi;
use App\Docker\Limits;
use App\Exec\NetworkMode;
use App\Exec\Slots;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use App\Session\SessionNetwork;

/**
 * One scratch build, from slot to result.
 *
 * The lifecycle, in the order the design fixes it — and it is deliberately
 * short, because a build is a daemon-side operation rather than a container
 * the broker launches and watches:
 *
 * 1. the session must exist (404, never created on the way past — the same
 *    rule as exec, for the same reason: the image is session-scoped, and a
 *    mistyped name must not become a durable object);
 * 2. a slot is held for the whole build — a build is work in the workspace's
 *    budget like any command, and §6b's unit is the workspace;
 * 3. the session network is ensured when (and only when) the request asked to
 *    reach it, because the daemon refuses `networkmode=<name>` for a network
 *    that does not exist;
 * 4. the query comes from `BuildSpec` — never from the caller — and the
 *    context streams to the daemon with the caller's timeout as the clock;
 * 5. the outcome is *asked*, not read from the log: the daemon is asked
 *    whether the tagged image exists — because the alternative is believing a
 *    text line this broker did not write — except when the daemon reported a
 *    failure, where the lookup is skipped so a stale image cannot be attached
 *    to a failed build (see the comment in `run()`).
 *
 * There is no container to kill and none to remove: the build's containers
 * belong to the daemon, `rm=1`/`forcerm=1` dispose of them, and the only
 * enforcement point that can exist for the build itself is the connection —
 * the daemon documents that a build whose client disconnects is cancelled,
 * and the client's own budget is what disconnects it (see
 * `HttpDockerApi::buildImage`). That is the whole of "cannot sit there
 * forever" for this endpoint, and it is worth stating because it is weaker
 * than exec's kill-at-deadline: a build that overruns is cut off, not
 * stopped gracefully.
 */
final class BuildRunner
{
    public function __construct(
        private readonly DockerApi $docker,
        private readonly Slots $slots,
        private readonly SessionNetwork $networks,
    ) {
    }

    /**
     * @param resource|iterable<string>|string $context the (uncapped) build context; capped here
     */
    public function run(SessionName $session, BuildRequest $request, mixed $context): BuildResult
    {
        if (!$this->docker->volumeExists($session->workspaceVolume())) {
            throw NoSuchSession::named($session->value);
        }

        // Refuses rather than queues, exactly as exec does (§6b): "waiting"
        // and "hung" must not look alike to a caller.
        $slot = $this->slots->reserve();

        try {
            if (NetworkMode::Session === $request->network) {
                $this->networks->ensure($session);
            }

            $limits = new Limits();
            $output = $this->docker->buildImage(
                BuildSpec::query($request, $session, $limits),
                ContextStream::capped($context, BuildRequest::MAX_CONTEXT_BYTES),
                (float) $request->timeout,
                $limits->outputByteCap,
            );

            // The outcome, asked of the daemon rather than parsed out of the
            // stream — but only when the daemon did not already report a
            // failure. A reported failure skips the lookup on purpose: the tag
            // is reusable (a rebuild replaces the previous image), so an image
            // that exists after a failed build may be the one the *last*
            // build produced, and returning it would attach a stale artefact
            // to a failure. With no reported failure the lookup is
            // authoritative — tagging only happens on success, so an image
            // that exists means the build finished even if the budget cut the
            // stream in the same instant.
            $image = null;

            if (!$output->failed) {
                $reference = BuildSpec::imageRef($session, $request->tag);
                $image = $this->docker->imageExists($reference) ? $reference : null;
            }

            return new BuildResult($image, $output->output, $output->truncated, $output->timedOut);
        } finally {
            $slot->release();
        }
    }
}
