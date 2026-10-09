<?php

namespace Keenthekeen\OAuthHelper\Admin;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Keenthekeen\OAuthHelper\Client\ClientCredentials;
use Keenthekeen\OAuthHelper\Exceptions\AdminApiException;
use Keenthekeen\OAuthHelper\Realm;

/**
 * Read-only access to the Keycloak Admin REST API of the configured realm, authenticated as the admin client's
 * service account. Give that client only the realm-management roles it needs (view-users for the user directory).
 */
class AdminClient {
    /**
     * @param  array<string, mixed>  $httpOptions
     */
    public function __construct(
        protected Realm $realm,
        protected ClientCredentials $credentials,
        protected Http $http,
        protected string $client = 'admin',
        protected array $httpOptions = [],
    ) {}

    /**
     * GET a path under /admin/realms/{realm}. Returns null for 404.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws AdminApiException
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
            throw new AdminApiException(match ($response->status()) {
                401, 403 => 'The Keycloak admin client is not allowed to read '.$path.'. Check its service-account roles.',
                default => 'The Keycloak Admin API failed to read '.$path.'.',
            }." (HTTP {$response->status()})", $response->status());
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws AdminApiException
     */
    protected function send(string $path, array $query): Response {
        try {
            return $this->http->withOptions($this->httpOptions)
                ->acceptJson()
                ->withToken($this->credentials->token(client: $this->client))
                ->get($this->realm->adminUrl($path), $query);
        } catch (ConnectionException $e) {
            throw new AdminApiException('Cannot connect to Keycloak: '.$e->getMessage());
        }
    }
}
