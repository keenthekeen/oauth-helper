<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Keenthekeen\OAuthHelper\Exceptions\OAuthHelperException;
use Keenthekeen\OAuthHelper\Exceptions\TokenRequestException;
use Keenthekeen\OAuthHelper\Facades\OAuth;
use Keenthekeen\OAuthHelper\Testing\FakeOAuthServer;

const TOKEN_URL = 'https://sso.test/realms/test/protocol/openid-connect/token';

it('requests a token with the scope and resource indicator', function () {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'token-1', 'expires_in' => 300])]);

    expect(OAuth::token('students_basic', 'https://precede.test'))->toBe('token-1');

    Http::assertSent(fn (Request $request) => $request->url() === TOKEN_URL
        and $request['grant_type'] === 'client_credentials'
        and $request['client_id'] === 'test-app'
        and $request['client_secret'] === 'secret'
        and $request['scope'] === 'students_basic'
        and $request['resource'] === 'https://precede.test');
});

it('caches the token per client, scope and resource', function () {
    Http::fakeSequence(TOKEN_URL)
        ->push(['access_token' => 'token-1', 'expires_in' => 300])
        ->push(['access_token' => 'token-2', 'expires_in' => 300])
        ->push(['access_token' => 'token-3', 'expires_in' => 300]);

    expect(OAuth::token('b a'))->toBe('token-1')
        ->and(OAuth::token(['a', 'b']))->toBe('token-1')
        ->and(OAuth::token(['a', 'b'], 'https://precede.test'))->toBe('token-2')
        ->and(OAuth::token(['a', 'b'], client: 'directory'))->toBe('token-3');
    Http::assertSentCount(3);
});

it('refreshes the token before it expires', function () {
    Http::fakeSequence(TOKEN_URL)
        ->push(['access_token' => 'token-1', 'expires_in' => 300])
        ->push(['access_token' => 'token-2', 'expires_in' => 300]);

    OAuth::token('a');
    $this->travel(269)->seconds();
    expect(OAuth::token('a'))->toBe('token-1');
    $this->travel(2)->seconds();
    expect(OAuth::token('a'))->toBe('token-2');
});

it('throws when the server refuses', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'invalid_scope', 'error_description' => 'Invalid scopes: nope'], 400)]);

    OAuth::token('nope');
})->throws(TokenRequestException::class, 'Invalid scopes: nope');

it('throws for an unconfigured client', function () {
    OAuth::token(client: 'missing');
})->throws(OAuthHelperException::class, 'The OAuth client "missing" is not configured.');

it('calls another service with the token', function () {
    Http::fake([
        TOKEN_URL => Http::response(['access_token' => 'token-1', 'expires_in' => 300]),
        'https://coop.test/graphql' => Http::response(['data' => ['user' => ['id' => 1]]]),
    ]);

    $response = OAuth::graphql('https://coop.test/graphql', 'query ($id: ID) { user(id: $id) { id } }', ['id' => 1], scope: 'students_basic');

    expect($response->json('data.user.id'))->toBe(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://coop.test/graphql'
        and $request->hasHeader('Authorization', 'Bearer token-1')
        and $request['variables'] === ['id' => 1]);
});

it('fakes the token endpoint in tests', function () {
    FakeOAuthServer::install()->fakeTokenEndpoint();

    expect(OAuth::token('students_basic'))->toBeString()->not->toBeEmpty();
});
