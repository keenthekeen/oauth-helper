<?php

namespace Keenthekeen\OAuthHelper;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Keenthekeen\OAuthHelper\Client\ClientCredentials;
use Keenthekeen\OAuthHelper\Client\ServiceClient;
use Keenthekeen\OAuthHelper\Directory\UserDirectory;
use Keenthekeen\OAuthHelper\Token\TokenVerifier;

/**
 * Entry point behind the OAuth facade.
 */
class OAuth {
    public function __construct(protected Container $app) {}

    public function server(): AuthorizationServer {
        return $this->app->make(AuthorizationServer::class);
    }

    public function users(): UserDirectory {
        return $this->app->make(UserDirectory::class);
    }

    public function verifier(): TokenVerifier {
        return $this->app->make(TokenVerifier::class);
    }

    /**
     * @param  string|array<int, string>  $scope
     */
    public function token(string|array $scope = [], ?string $resource = null, string $client = 'default'): string {
        return $this->app->make(ClientCredentials::class)->token($scope, $resource, $client);
    }

    /**
     * @param  string|array<int, string>  $scope
     */
    public function http(string|array $scope = [], ?string $resource = null, string $client = 'default'): PendingRequest {
        return $this->app->make(ServiceClient::class)->request($scope, $resource, $client);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  string|array<int, string>  $scope
     */
    public function graphql(string $url, string $query, array $variables = [], string|array $scope = [], ?string $resource = null, string $client = 'default'): Response {
        return $this->app->make(ServiceClient::class)->graphql($url, $query, $variables, $scope, $resource, $client);
    }
}
