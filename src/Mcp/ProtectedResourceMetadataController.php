<?php

namespace Keenthekeen\OAuthHelper\Mcp;

use Illuminate\Http\JsonResponse;

/**
 * Serves the OAuth 2.0 Protected Resource Metadata (RFC 9728) of the MCP server.
 * Register it with Route::mcpProtectedResource().
 */
class ProtectedResourceMetadataController {
    public function __invoke(): JsonResponse {
        return response()->json(McpResource::metadata())
            ->header('Access-Control-Allow-Origin', '*');
    }
}
