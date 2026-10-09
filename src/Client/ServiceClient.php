<?php

namespace Keenthekeen\OAuthHelper\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Keenthekeen\OAuthHelper\Exceptions\TokenRequestException;

/**
 * Calls other services' APIs with this app's client-credentials access token.
 */
class ServiceClient {
    /**
     * @param  array<string, mixed>  $httpOptions
     */
    public function __construct(
        protected ClientCredentials $credentials,
        protected Http $http,
        protected array $httpOptions = [],
    ) {}

    /**
     * A JSON request carrying an access token for the scope and resource.
     *
     * @param  string|array<int, string>  $scope
     * @param  string|null  $resource  RFC 8707 resource indicator, typically the service's base URL
     *
     * @throws TokenRequestException|ConnectionException
     */
    public function request(string|array $scope = [], ?string $resource = null, string $client = 'default'): PendingRequest {
        return $this->http->withOptions($this->httpOptions)
            ->asJson()
            ->acceptJson()
            ->withToken($this->credentials->token($scope, $resource, $client));
    }

    /**
     * Send a GraphQL query. Inspect `$response->json('errors')` for GraphQL errors, which come with HTTP 200.
     *
     * @param  array<string, mixed>  $variables
     * @param  string|array<int, string>  $scope
     *
     * @throws TokenRequestException|ConnectionException
     */
    public function graphql(string $url, string $query, array $variables = [], string|array $scope = [], ?string $resource = null, string $client = 'default'): Response {
        return $this->request($scope, $resource, $client)->post($url, array_filter([
            'query' => $query,
            'variables' => $variables ?: null,
        ]));
    }
}
