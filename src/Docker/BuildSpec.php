<?php

declare(strict_types=1);

namespace App\Docker;

use App\Build\BuildRequest;
use App\Build\BuildTag;
use App\Session\SessionName;

/**
 * The one place a `POST /build` query is assembled.
 *
 * The same argument as `ContainerSpec`, applied to the build endpoint: the
 * daemon's `/build` carries a wide query surface — `remote`, `extrahosts`,
 * `cachefrom`, `buildargs`, `outputs`, `platform`, `dockerfile` — and the
 * broker forwards none of it. It builds the query itself, field by field,
 * from typed input, so what cannot be expressed cannot be forwarded.
 *
 * Two decisions are pinned here and both are measured against Docker 29.8:
 *
 * - **`version=1`, the classic builder, deliberately.** BuildKit refuses
 *   custom networks ("network mode … not supported by buildkit"), and the
 *   session network is exactly what a `network: session` build needs; the
 *   classic builder accepts it. The classic builder is also deprecated
 *   upstream — so when it is removed, this line is what has to be revisited,
 *   and the reopen condition is BuildKit gaining custom-network support (or a
 *   different egress design). Recorded in DOCKER-ACCESS.md §10f.
 * - **`rm` and `forcerm` both set.** A scratch build must not leave
 *   intermediate containers behind, whether it succeeds or fails.
 */
final class BuildSpec
{
    /**
     * The full image reference a scratch build produces: `ww-<session>-<tag>:latest`.
     *
     * One spelling, used twice: it is what the build query tags, and it is
     * what the runner asks the daemon about afterwards. Two spellings that had
     * to agree would be a drift waiting to happen, so there is one.
     */
    public static function imageRef(SessionName $session, BuildTag $tag): string
    {
        return $session->buildImageRef($tag).':latest';
    }

    /**
     * @return array<string, string>
     */
    public static function query(BuildRequest $request, SessionName $session, Limits $limits): array
    {
        return [
            't' => self::imageRef($session, $request->tag),
            'version' => '1',
            'rm' => '1',
            'forcerm' => '1',
            'networkmode' => $request->network->dockerNetwork($session->network()),
            'labels' => json_encode(Labels::buildImage($session), \JSON_THROW_ON_ERROR),
            'memory' => (string) $limits->memoryBytes,
        ];
    }
}
