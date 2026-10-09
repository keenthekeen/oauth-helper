<?php

use Keenthekeen\OAuthHelper\AuthorizationServer;
use Keenthekeen\OAuthHelper\Directory\Keycloak\KeycloakAdminClient;
use Keenthekeen\OAuthHelper\Exceptions\DirectoryException;
use Keenthekeen\OAuthHelper\Exceptions\OAuthHelperException;
use Keenthekeen\OAuthHelper\Token\Scopes;
use Keenthekeen\OAuthHelper\Token\StrongAuth;
use Keenthekeen\OAuthHelper\Token\Url;

it('derives endpoints from the issuer', function () {
    $server = new AuthorizationServer('https://sso.test/realms/med/');

    expect($server->issuer())->toBe('https://sso.test/realms/med')
        ->and($server->jwksUrl())->toBe('https://sso.test/realms/med/protocol/openid-connect/certs')
        ->and($server->tokenEndpoint())->toBe('https://sso.test/realms/med/protocol/openid-connect/token');
});

it('uses configured endpoints', function () {
    $server = new AuthorizationServer('https://auth.test', 'https://auth.test/.well-known/jwks.json', 'https://auth.test/oauth/token');

    expect($server->jwksUrl())->toBe('https://auth.test/.well-known/jwks.json')
        ->and($server->tokenEndpoint())->toBe('https://auth.test/oauth/token');
});

it('requires an issuer', function () {
    (new AuthorizationServer(null))->issuer();
})->throws(OAuthHelperException::class);

it('derives the Keycloak admin URL from the issuer', function () {
    expect(KeycloakAdminClient::adminUrlFromIssuer('https://sso.test/realms/med'))->toBe('https://sso.test/admin/realms/med');
});

it('refuses to derive an admin URL from another issuer', function () {
    KeycloakAdminClient::adminUrlFromIssuer('https://auth.test');
})->throws(DirectoryException::class);

it('collects scopes from scope and scp', function () {
    expect(Scopes::fromClaims(['scope' => 'openid  b a', 'scp' => ['c', 'a', 1]]))->toBe(['a', 'b', 'c', 'openid'])
        ->and(Scopes::hasAny(['scope' => 'a b'], ['x', 'b']))->toBeTrue()
        ->and(Scopes::hasAny([], ['a']))->toBeFalse();
});

it('recognizes strong authentication', function (array $claims, bool $strong) {
    expect(StrongAuth::fromClaims($claims))->toBe($strong);
})->with([
    'acr strong' => [['acr' => 'strong'], true],
    'acr 2' => [['acr' => '2'], true],
    'acr simple' => [['acr' => 'simple'], false],
    'otp' => [['amr' => ['pwd', 'otp']], true],
    'password only' => [['amr' => ['pwd']], false],
    'google' => [['identity_provider' => 'google'], true],
    'nothing' => [[], false],
]);

it('matches URLs under a base URL', function (string $url, bool $within) {
    expect(Url::isWithin($url, 'https://app.test/base'))->toBe($within);
})->with([
    ['https://app.test/base', true],
    ['https://APP.test/base/', true],
    ['https://app.test/base/api', true],
    ['https://app.test/basement', false],
    ['http://app.test/base', false],
    ['https://app.test:8443/base', false],
    ['https://app.test/base?x=1', false],
    ['app.test/base', false],
]);
