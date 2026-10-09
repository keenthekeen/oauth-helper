<?php

namespace Keenthekeen\OAuthHelper\Guard;

use Keenthekeen\OAuthHelper\Mcp\McpResource;
use Keenthekeen\OAuthHelper\Token\Url;

/**
 * Which `aud` values mean "this app's API".
 */
class ApiAudience {
    /**
     * The configured API audience, or (with accept_app_url) APP_URL or a URL under it,
     * except the MCP server's, whose tokens are only for MCP clients.
     */
    public static function matches(string $audience): bool {
        $configured = config('oauth-helper.api.audience');
        if ($configured !== null and $audience === $configured) {
            return true;
        }

        return config('oauth-helper.api.accept_app_url')
            and Url::isWithin($audience, (string) config('app.url'))
            and ! Url::isWithin($audience, McpResource::url());
    }
}
