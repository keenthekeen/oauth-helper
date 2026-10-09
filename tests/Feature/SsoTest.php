<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Keenthekeen\OAuthHelper\Sso\OidcSession;
use Keenthekeen\OAuthHelper\Sso\OidcSessionRegistry;
use Keenthekeen\OAuthHelper\Tests\Fixtures\User;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    $this->user = User::create(['name' => 'Somchai', 'email' => 'somchai@example.com']);
    $this->registry = app(OidcSessionRegistry::class);
});

function requestWithSession(string $id): Request {
    $session = app('session')->driver();
    $session->setId($id);
    $request = Request::create('/');
    $request->setLaravelSession($session);

    return $request;
}

it('maps the local session to the SSO session and keeps the id_token', function () {
    $request = requestWithSession(str_repeat('a', 40));

    $this->registry->remember($request, $this->user, ['sid' => 'sid-1', 'sub' => 'sub-1'], 'id-token');

    expect(OidcSession::find(str_repeat('a', 40)))->sid->toBe('sid-1')->user_id->toBe($this->user->id)
        ->and($this->registry->forget($request))->toBe('id-token')
        ->and(OidcSession::count())->toBe(0);
});

it('destroys every local session of a logged-out SSO session', function () {
    foreach (['a', 'b'] as $letter) {
        $this->registry->remember(requestWithSession(str_repeat($letter, 40)), $this->user, ['sid' => 'sid-1', 'sub' => 'sub-1']);
    }
    $this->registry->remember(requestWithSession(str_repeat('c', 40)), $this->user, ['sid' => 'sid-2', 'sub' => 'sub-1']);

    expect($this->registry->destroy(['sid' => 'sid-1', 'sub' => 'sub-1']))->toBe(2)
        ->and(OidcSession::pluck('sid')->all())->toBe(['sid-2'])
        ->and($this->registry->destroy(['sub' => 'sub-1']))->toBe(1);
});

it('prunes mappings older than SSO Session Max', function () {
    $this->registry->remember(requestWithSession(str_repeat('a', 40)), $this->user, ['sid' => 'sid-1', 'sub' => 'sub-1']);
    $this->travel(config('oauth-helper.sso.session_max') + 1)->seconds();

    expect($this->registry->prune())->toBe(1);
});

describe('back-channel logout endpoint', function () {
    beforeEach(fn () => Route::oidcBackchannelLogout());

    it('destroys the sessions named by a verified logout token', function () {
        $this->registry->remember(requestWithSession(str_repeat('a', 40)), $this->user, ['sid' => 'sid-1', 'sub' => 'sub-1']);
        $handler = app('session')->driver()->getHandler();
        $handler->write(str_repeat('a', 40), 'payload');
        Socialite::shouldReceive('driver->verifyLogoutToken')->with('logout-token')->andReturn(['sid' => 'sid-1', 'sub' => 'sub-1']);

        $this->post('/auth/sso/backchannel-logout', ['logout_token' => 'logout-token'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        expect(OidcSession::count())->toBe(0)
            ->and($handler->read(str_repeat('a', 40)))->toBe('');
    });

    it('rejects an invalid logout token', function (?string $token) {
        Socialite::shouldReceive('driver->verifyLogoutToken')->andThrow(new InvalidArgumentException('Logout token: bad signature.'));

        $this->post('/auth/sso/backchannel-logout', array_filter(['logout_token' => $token]))
            ->assertBadRequest()
            ->assertExactJson(['error' => 'invalid_request']);
    })->with(['missing' => [null], 'invalid' => ['forged']]);
});
