<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Keenthekeen\OAuthHelper\Directory\DirectoryUser;
use Keenthekeen\OAuthHelper\Directory\SearchMode;
use Keenthekeen\OAuthHelper\Exceptions\DirectoryException;
use Keenthekeen\OAuthHelper\Facades\OAuth;
use Keenthekeen\OAuthHelper\Testing\FakeOAuthServer;

const ADMIN_URL = 'https://sso.test/admin/realms/test';

function somchai(array $overrides = []): array {
    return [
        'id' => 'f1d2e3c4-0000-4000-8000-000000000001',
        'username' => '6430012345',
        'email' => '6430012345@student.example.com',
        'firstName' => 'Somchai',
        'lastName' => 'Jaidee',
        'enabled' => true,
        'attributes' => [
            'studentId' => ['6430012345'],
            'first_name' => ['สมชาย'],
            'last_name' => ['ใจดี'],
            'phone' => ['0812345678'],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
});

function fakeAdminApi(array $responses): void {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'admin-token', 'expires_in' => 300]),
        ...$responses,
    ]);
}

it('searches as the directory client and keeps only allowlisted attributes', function () {
    fakeAdminApi([ADMIN_URL.'/users*' => Http::response([somchai()])]);

    $users = OAuth::users()->search('Somchai');

    expect($users)->toHaveCount(1)
        ->and($users[0])->toBeInstanceOf(DirectoryUser::class)
        ->and($users[0]->attribute('studentId'))->toBe('6430012345')
        ->and($users[0]->attributes)->not->toHaveKey('phone')
        ->and($users[0]->thaiName()?->full())->toBe('สมชาย ใจดี');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token') and $request['client_id'] === 'test-app-directory');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), ADMIN_URL.'/users?')
        and $request->hasHeader('Authorization', 'Bearer admin-token')
        and $request['search'] === 'Somchai*'
        and $request['max'] === 50
        and $request['first'] === 0);
});

it('strips search syntax from the query and applies the mode', function () {
    fakeAdminApi([ADMIN_URL.'/users*' => Http::response([])]);

    OAuth::users()->search(' "*jai*" ', SearchMode::Infix, max: 500);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users?')
        and $request['search'] === '*jai*'
        and $request['max'] === 50);
});

it('refuses short queries', function () {
    OAuth::users()->search('**a ');
})->throws(InvalidArgumentException::class, 'Search for at least 3 characters.');

it('caches results briefly', function () {
    fakeAdminApi([ADMIN_URL.'/users*' => Http::response([somchai()])]);

    OAuth::users()->search('Somchai');
    OAuth::users()->search('Somchai');
    $this->travel(61)->seconds();
    OAuth::users()->search('Somchai');

    Http::assertSentCount(3); // token + 2 searches
});

it('finds users', function () {
    fakeAdminApi([
        ADMIN_URL.'/users/f1d2e3c4-0000-4000-8000-000000000001' => Http::response(somchai()),
        ADMIN_URL.'/users/f1d2e3c4-0000-4000-8000-000000000002' => Http::response(['error' => 'User not found'], 404),
        ADMIN_URL.'/users?*' => Http::response([somchai()]),
        ADMIN_URL.'/users/count*' => Http::response('7'),
    ]);
    $directory = OAuth::users();

    expect($directory->find('f1d2e3c4-0000-4000-8000-000000000001')?->username)->toBe('6430012345')
        ->and($directory->find('f1d2e3c4-0000-4000-8000-000000000002'))->toBeNull()
        ->and($directory->find('../../clients'))->toBeNull()
        ->and($directory->findByUsername('6430012345')?->email)->toBe('6430012345@student.example.com')
        ->and($directory->findByAttribute('studentId', '6430012345'))->toHaveCount(1)
        ->and($directory->count('jaidee'))->toBe(7);
    Http::assertSent(fn (Request $request) => ($request['q'] ?? null) === 'studentId:6430012345' and $request['exact'] === 'true');
});

it('refuses attribute queries it cannot express', function (string $name, string $value) {
    OAuth::users()->findByAttribute($name, $value);
})->throws(InvalidArgumentException::class)->with([
    ['studentId', '64300 12345'],
    ['studentId', 'a:b'],
    ['student id', '1'],
    ['studentId', ''],
]);

it('retries once with a fresh token when the token is rejected', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::sequence()
            ->push(['access_token' => 'old-token', 'expires_in' => 300])
            ->push(['access_token' => 'new-token', 'expires_in' => 300]),
        ADMIN_URL.'/users*' => Http::sequence()->push([], 401)->push([somchai()]),
    ]);

    expect(OAuth::users()->search('Somchai'))->toHaveCount(1);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer new-token'));
});

it('explains a missing role', function () {
    fakeAdminApi([ADMIN_URL.'/users*' => Http::response(['error' => 'unknown_error'], 403)]);

    OAuth::users()->search('Somchai');
})->throws(DirectoryException::class, 'Check its service-account roles');

it('can be faked in tests', function () {
    $directory = FakeOAuthServer::install()->fakeUsers([somchai(), somchai(['id' => 'x', 'username' => 'somsri', 'firstName' => 'Somsri', 'email' => 'somsri@example.com'])]);

    expect(OAuth::users()->search('soms'))->toHaveCount(1)
        ->and(OAuth::users()->search('jaidee'))->toHaveCount(2)
        ->and(OAuth::users()->search('aide', SearchMode::Infix))->toHaveCount(2)
        ->and(OAuth::users()->findByEmail('SOMSRI@example.com')?->id)->toBe('x');
    $directory->assertSearched('soms');
    Http::assertNothingSent();
});
