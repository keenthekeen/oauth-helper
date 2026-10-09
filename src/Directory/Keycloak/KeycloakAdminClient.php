<?php

namespace Keenthekeen\OAuthHelper\Directory\Keycloak;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Keenthekeen\OAuthHelper\Client\ClientCredentials;
use Keenthekeen\OAuthHelper\Exceptions\DirectoryException;

/**
 * Read-only access to the Keycloak Admin REST API of one realm, authenticated as a client's service account.
 * The user directory needs the realm-management "view-users" role on that service account.
 */
class KeycloakAdminClient {
    /**
     * @param  array<string, mixed>  $httpOptions
     */
    public function __construct(
        protected string $adminUrl,
        protected ClientCredentials $credentials,
        protected Http $http,
        protected string $client = 'default',
        protected array $httpOptions = [],
    ) {
        $this->adminUrl = rtrim($adminUrl, '/');
    }

    /**
     * Admin URL of the realm an issuer belongs to: "{base}/realms/{realm}" becomes "{base}/admin/realms/{realm}".
     */
    public static function adminUrlFromIssuer(string $issuer): string {
        $adminUrl = preg_replace('#/realms/([^/]+)/?$#', '/admin/realms/$1', $issuer, 1, $count);
        if ($count !== 1) {
            throw new DirectoryException("Cannot derive the Keycloak admin URL from the issuer \"$issuer\"; set KEYCLOAK_ADMIN_URL.");
        }

        return $adminUrl;
    }

    /**
     * GET a path under the realm's admin URL. Returns null for 404.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws DirectoryException
     */
    public function get(string $path, array $query = []): mixed {
        $response = $this->send($path, $query);
        if ($response->status() === 401) {
            // The cached token was revoked or the client's roles changed: retry once with a fresh token
            $this->credentials->forget(client: $this->client);
            $response = $this->send($path, $query);
        }

        if ($response->notFound()) {
            return null;
        }
        if (! $response->successful()) {
            throw new DirectoryException(match ($response->status()) {
                401, 403 => "The OAuth client \"{$this->client}\" is not allowed to read $path. Check its service-account roles.",
                default => 'The Keycloak Admin API failed to read '.$path.'.',
            }." (HTTP {$response->status()})", $response->status());
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws DirectoryException
     */
    protected function send(string $path, array $query): Response {
        try {
            return $this->http->withOptions($this->httpOptions)
                ->acceptJson()
                ->withToken($this->credentials->token(client: $this->client))
                ->get($this->adminUrl.'/'.ltrim($path, '/'), $query);
        } catch (ConnectionException $e) {
            throw new DirectoryException('Cannot connect to Keycloak: '.$e->getMessage());
        }
    }
}
