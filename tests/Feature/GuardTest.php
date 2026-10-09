<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Keenthekeen\OAuthHelper\Exceptions\InvalidTokenException;
use Keenthekeen\OAuthHelper\Facades\OAuth;
use Keenthekeen\OAuthHelper\Guard\ApiClient;
use Keenthekeen\OAuthHelper\Mcp\McpGuard;
use Keenthekeen\OAuthHelper\Testing\FakeOAuthServer;
use Keenthekeen\OAuthHelper\Tests\Fixtures\User;

beforeEach(function () {
    $this->server = FakeOAuthServer::install();

    Route::get('/api/whoami', fn () => [
        'client' => Auth::guard('api')->id(),
        'scopes' => Auth::guard('api')->user()->scopes(),
    ])->middleware('auth:api');

    Route::get('/mcp-whoami', fn () => [
        'email' => Auth::guard('mcp')->user()->email,
        'strong' => Auth::guard('mcp')->isStrongAuth(),
    ])->middleware('auth:mcp');
});

describe('token verifier', function () {
    it('returns the claims of a valid token', function () {
        $claims = OAuth::verifier()->verify($this->server->token(['aud' => 'x', 'sub' => 'abc']), 'x');

        expect($claims['sub'])->toBe('abc');
    });

    it('rejects a token', function (Closure $token, ?string $audience) {
        OAuth::verifier()->verify($token($this->server), $audience);
    })->throws(InvalidTokenException::class)->with([
        'from another issuer' => [fn ($s) => $s->token(['iss' => 'https://evil.test/realms/test']), null],
        'expired' => [fn ($s) => $s->token(['exp' => time() - 3600]), null],
        'for another audience' => [fn ($s) => $s->token(['aud' => ['other']]), 'x'],
        'signed with another key' => [fn ($s) => (new FakeOAuthServer)->token(), null],
        'malformed' => [fn ($s) => 'not-a-jwt', null],
    ]);
});

describe('API guard', function () {
    it('authenticates a client with the API audience', function () {
        $this->withToken($this->server->apiToken('reporting', ['students_basic']))
            ->getJson('/api/whoami')
            ->assertOk()
            ->assertExactJson(['client' => 'reporting', 'scopes' => ['students_basic']]);
    });

    it('accepts the app URL as audience', function () {
        $this->withToken($this->server->apiToken(claims: ['aud' => 'https://app.test']))
            ->getJson('/api/whoami')
            ->assertOk();
    });

    it('rejects a token', function (array $claims) {
        $this->withToken($this->server->apiToken(claims: $claims))
            ->getJson('/api/whoami')
            ->assertUnauthorized();
    })->with([
        'for another client' => [['aud' => ['account', 'other-app']]],
        'for the MCP server' => [['aud' => 'https://app.test/mcp']],
        'without azp' => [['azp' => null]],
        'expired' => [['exp' => time() - 3600]],
    ]);

    it('rejects a request without a token', function () {
        $this->getJson('/api/whoami')->assertUnauthorized();
    });

    it('lets tests act as a client', function () {
        $this->actingAs(new ApiClient('fake', scopes: ['a']), 'api')
            ->getJson('/api/whoami')
            ->assertJsonPath('client', 'fake');
    });
});

describe('MCP guard', function () {
    beforeEach(fn () => User::create(['name' => 'Somchai', 'email' => 'somchai@example.com']));

    it('authenticates the user of a delegated token', function () {
        $this->withToken($this->server->mcpToken('somchai@example.com', strongAuth: true))
            ->getJson('/mcp-whoami')
            ->assertOk()
            ->assertExactJson(['email' => 'somchai@example.com', 'strong' => true]);
    });

    it('reports a sign-in without strong authentication', function () {
        $this->withToken($this->server->mcpToken('somchai@example.com'))
            ->getJson('/mcp-whoami')
            ->assertJsonPath('strong', false);
    });

    it('rejects a token', function (Closure $token) {
        $this->withToken($token($this->server))
            ->getJson('/mcp-whoami')
            ->assertUnauthorized();
    })->with([
        'for the API' => [fn ($s) => $s->apiToken(claims: ['email' => 'somchai@example.com', 'scope' => 'mcp:resources'])],
        'without the MCP scope' => [fn ($s) => $s->mcpToken('somchai@example.com', claims: ['scope' => 'openid'])],
        'of an unknown user' => [fn ($s) => $s->mcpToken('stranger@example.com')],
    ]);

    it('resolves users with a custom resolver', function () {
        McpGuard::resolveUsersUsing(fn (array $claims) => User::where('name', 'Somchai')->first());

        $this->withToken($this->server->mcpToken('alias@example.com'))
            ->getJson('/mcp-whoami')
            ->assertJsonPath('email', 'somchai@example.com');
    });

    it('serves the protected resource metadata', function () {
        Route::mcpProtectedResource();

        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson([
                'resource' => 'https://app.test/mcp',
                'authorization_servers' => [FakeOAuthServer::ISSUER],
                'scopes_supported' => ['mcp:resources'],
                'bearer_methods_supported' => ['header'],
            ]);
    });
});
