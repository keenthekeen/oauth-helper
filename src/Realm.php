<?php

namespace Keenthekeen\OAuthHelper;

use Keenthekeen\OAuthHelper\Exceptions\OAuthHelperException;

/**
 * URLs of the configured Keycloak realm.
 */
class Realm {
    public function __construct(
        protected string $baseUrl,
        protected ?string $realm,
        protected ?string $issuer = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromConfig(): self {
        return new self(
            (string) config('oauth-helper.base_url'),
            config('oauth-helper.realm'),
            config('oauth-helper.issuer'),
        );
    }

    public function name(): string {
        if (empty($this->realm)) {
            throw new OAuthHelperException('The Keycloak realm is not configured (KEYCLOAK_REALM).');
        }

        return $this->realm;
    }

    public function baseUrl(): string {
        return $this->baseUrl;
    }

    public function issuer(): string {
        return $this->issuer ?: $this->baseUrl.'/realms/'.$this->name();
    }

    public function jwksUrl(): string {
        return $this->baseUrl.'/realms/'.$this->name().'/protocol/openid-connect/certs';
    }

    public function tokenEndpoint(): string {
        return $this->baseUrl.'/realms/'.$this->name().'/protocol/openid-connect/token';
    }

    public function adminUrl(string $path = ''): string {
        return $this->baseUrl.'/admin/realms/'.$this->name().($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
