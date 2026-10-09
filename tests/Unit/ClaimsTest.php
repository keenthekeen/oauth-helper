<?php

use Keenthekeen\OAuthHelper\Realm;
use Keenthekeen\OAuthHelper\Token\Scopes;
use Keenthekeen\OAuthHelper\Token\StrongAuth;
use Keenthekeen\OAuthHelper\Token\Url;

it('derives the realm URLs', function () {
    $realm = new Realm('https://sso.test/', 'med');

    expect($realm->issuer())->toBe('https://sso.test/realms/med')
        ->and($realm->jwksUrl())->toBe('https://sso.test/realms/med/protocol/openid-connect/certs')
        ->and($realm->tokenEndpoint())->toBe('https://sso.test/realms/med/protocol/openid-connect/token')
        ->and($realm->adminUrl('users/count'))->toBe('https://sso.test/admin/realms/med/users/count')
        ->and((new Realm('https://sso.test', 'med', 'https://proxy.test/realms/med'))->issuer())->toBe('https://proxy.test/realms/med');
});

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
