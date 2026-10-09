<?php

namespace Keenthekeen\OAuthHelper\Client;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Keenthekeen\OAuthHelper\AuthorizationServer;
use Keenthekeen\OAuthHelper\Exceptions\OAuthHelperException;
use Keenthekeen\OAuthHelper\Exceptions\TokenRequestException;

/**
 * Access tokens for this app's confidential clients (client credentials grant), cached until shortly before they expire.
 */
class ClientCredentials {
    /** Cache lifetime when the server does not say how long the token lives */
    protected const FALLBACK_TTL = 180;

    /**
     * @param  array<string, array{client_id: ?string, client_secret: ?string}>  $clients
     * @param  array<string, mixed>  $httpOptions
     */
    public function __construct(
        protected AuthorizationServer $server,
        protected Http $http,
        protected Cache $cache,
        protected array $clients,
        protected array $httpOptions = [],
    ) {}

    /**
     * Get an access token for the client.
     *
     * @param  string|array<int, string>  $scope  Space-delimited string or list of scopes; none for the client's default scopes
     * @param  string|null  $resource  RFC 8707 resource indicator: URI of the service the token is for, which the server puts in `aud`
     * @param  string  $client  Name of a client in config('oauth-helper.clients')
     *
     * @throws TokenRequestException|ConnectionException
     */
    public function token(string|array $scope = [], ?string $resource = null, string $client = 'default'): string {
        $scope = $this->normalizeScope($scope);
        $key = $this->cacheKey($client, $scope, $resource);
        if (is_string($token = $this->cache->get($key))) {
            return $token;
        }

        // Concurrent requests with a cold cache wait for one token request instead of each sending their own
        $store = $this->cache->getStore();
        if ($store instanceof LockProvider) {
            return $store->lock($key.':lock', 10)->block(10, function () use ($key, $client, $scope, $resource) {
                return $this->cache->get($key) ?: $this->requestToken($key, $client, $scope, $resource);
            });
        }

        return $this->requestToken($key, $client, $scope, $resource);
    }

    /**
     * Forget a cached token, e.g. after a service rejected it.
     *
     * @param  string|array<int, string>  $scope
     */
    public function forget(string|array $scope = [], ?string $resource = null, string $client = 'default'): void {
        $this->cache->forget($this->cacheKey($client, $this->normalizeScope($scope), $resource));
    }

    /**
     * @throws TokenRequestException|ConnectionException
     */
    protected function requestToken(string $key, string $client, string $scope, ?string $resource): string {
        $credentials = $this->clients[$client] ?? null;
        if (empty($credentials['client_id']) or empty($credentials['client_secret'])) {
            throw new OAuthHelperException("The OAuth client \"$client\" is not configured.");
        }

        $response = $this->http->withOptions($this->httpOptions)->asForm()->acceptJson()->post($this->server->tokenEndpoint(), array_filter([
            'grant_type' => 'client_credentials',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'scope' => $scope,
            'resource' => $resource,
        ], fn ($value) => $value !== null and $value !== ''));

        $token = $response->json('access_token');
        if (! $response->successful() or ! is_string($token) or $token === '') {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'no access_token';
            throw new TokenRequestException("The authorization server refused the client credentials grant for \"$client\" (HTTP {$response->status()}): $error");
        }

        $expiresIn = (int) $response->json('expires_in', 0);
        // Leave a margin so a token is never sent just as it expires
        $ttl = $expiresIn > 0 ? $expiresIn - min(30, intdiv($expiresIn, 2)) : self::FALLBACK_TTL;
        $this->cache->put($key, $token, $ttl);

        return $token;
    }

    /**
     * @param  string|array<int, string>  $scope
     */
    protected function normalizeScope(string|array $scope): string {
        $scopes = is_array($scope) ? $scope : preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY);
        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        return implode(' ', $scopes);
    }

    protected function cacheKey(string $client, string $scope, ?string $resource): string {
        return 'oauth-helper:token:'.hash('xxh128', $client."\0".$scope."\0".$resource);
    }
}
