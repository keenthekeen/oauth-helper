<?php

namespace Keenthekeen\OAuthHelper;

use Keenthekeen\OAuthHelper\Exceptions\OAuthHelperException;

/**
 * Endpoints of the OAuth 2.0 / OpenID Connect authorization server.
 *
 * Unset endpoints follow Keycloak's layout under the issuer ("{issuer}/protocol/openid-connect/..."),
 * so a Keycloak realm needs only its issuer; set them explicitly for other servers.
 */
class AuthorizationServer {
    public function __construct(
        protected ?string $issuer,
        protected ?string $jwksUrl = null,
        protected ?string $tokenEndpoint = null,
    ) {
        $this->issuer = $issuer === null ? null : rtrim($issuer, '/');
    }

    public static function fromConfig(): self {
        return new self(
            config('oauth-helper.issuer'),
            config('oauth-helper.jwks_url'),
            config('oauth-helper.token_endpoint'),
        );
    }

    public function issuer(): string {
        if (empty($this->issuer)) {
            throw new OAuthHelperException('The authorization server is not configured (OAUTH_ISSUER).');
        }

        return $this->issuer;
    }

    public function jwksUrl(): string {
        return $this->jwksUrl ?: $this->issuer().'/protocol/openid-connect/certs';
    }

    public function tokenEndpoint(): string {
        return $this->tokenEndpoint ?: $this->issuer().'/protocol/openid-connect/token';
    }
}
