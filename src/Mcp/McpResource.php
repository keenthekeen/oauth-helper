<?php

namespace Keenthekeen\OAuthHelper\Mcp;

use Keenthekeen\OAuthHelper\Realm;

class McpResource {
    /**
     * Canonical URL of the MCP server, which MCP access tokens must carry in `aud` (RFC 8707 resource indicator).
     */
    public static function url(): string {
        return config('oauth-helper.mcp.resource') ?: rtrim((string) config('app.url'), '/').'/mcp';
    }

    /**
     * Scope the user must have consented to for the MCP client to act on their behalf.
     */
    public static function scope(): string {
        return (string) config('oauth-helper.mcp.scope');
    }

    /**
     * OAuth 2.0 Protected Resource Metadata (RFC 9728), pointing MCP clients to Keycloak.
     *
     * @return array<string, mixed>
     */
    public static function metadata(): array {
        return [
            'resource' => self::url(),
            'authorization_servers' => [app(Realm::class)->issuer()],
            'scopes_supported' => [self::scope()],
            'bearer_methods_supported' => ['header'],
        ];
    }
}
