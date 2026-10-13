<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Server\Builder;

/**
 * Declares `ww://pkg` on the server builder.
 *
 * Tools come from the catalogue; the one resource is declared here rather than
 * in it, for the reason ContextShuttle's `CountResourceRegistrar` gives: a
 * resource has a different shape from a tool (a URI and a MIME type, no
 * arguments and no JSON Schema), and stretching the tool catalogue to cover
 * both would be a worse contract than one small declaration in code.
 *
 * The handler is `[class, method]`, not a bare string, because that is the form
 * the SDK resolves through PSR-11 — a bare string is read as a global function
 * name and the resource would lose its collaborators. Same reason every tool
 * handler is registered that way.
 */
final class McpResourceRegistrar
{
    public function declareOn(Builder $builder): void
    {
        $builder->addResource(
            handler: [PackageManifest::class, 'read'],
            uri: PackageManifest::URI,
            name: 'package',
            title: 'WorkWarp package manifest',
            description: 'How to call this server: the endpoint and its auth, what a session is, the '
                .'shape of a command, and the tool list with its schemas. Read this once at the start.',
            mimeType: PackageManifest::MIME_TYPE,
        );
    }
}
