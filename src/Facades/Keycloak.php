<?php

namespace Keenthekeen\OAuthHelper\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Keenthekeen\OAuthHelper\Realm realm()
 * @method static \Keenthekeen\OAuthHelper\Contracts\UserDirectory users()
 * @method static \Keenthekeen\OAuthHelper\Token\TokenVerifier verifier()
 * @method static string token(string|array $scope = [], ?string $resource = null, string $client = 'default')
 * @method static \Illuminate\Http\Client\PendingRequest http(string|array $scope = [], ?string $resource = null, string $client = 'default')
 * @method static \Illuminate\Http\Client\Response graphql(string $url, string $query, array $variables = [], string|array $scope = [], ?string $resource = null, string $client = 'default')
 *
 * @see \Keenthekeen\OAuthHelper\Keycloak
 */
class Keycloak extends Facade {
    protected static function getFacadeAccessor(): string {
        return \Keenthekeen\OAuthHelper\Keycloak::class;
    }
}
