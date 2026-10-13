<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /v1/pkg` — the package manifest over plain HTTP.
 *
 * The same document the `ww://pkg` resource serves, for the caller that is
 * writing an integration and does not want to speak MCP to read the
 * documentation. It is bearer-gated like everything under `/v1`, which is
 * correct rather than inconvenient: the manifest describes what the token
 * unlocks, and it names no secret.
 *
 * `GET /v1/pkg/tools` is the tool list alone, which is the piece a generator
 * or a diff tool wants — the same shapes the MCP `tools/list` returns.
 */
final class PackageController
{
    public function __construct(
        private readonly PackageManifest $manifest,
    ) {
    }

    #[Route('/v1/pkg', name: 'package_manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        return new JsonResponse($this->manifest->toArray(), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/v1/pkg/tools', name: 'package_tools', methods: ['GET'])]
    public function tools(): JsonResponse
    {
        $manifest = $this->manifest->toArray();

        return new JsonResponse(
            ['tools' => $manifest['tools']],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
