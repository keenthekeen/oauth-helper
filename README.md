# keenthekeen/oauth-helper

Keycloak OAuth/OIDC helpers shared by the Laravel apps (Precede, Coop, Rollcall, FlickMan):

| Module | What it replaces |
|---|---|
| `Token` – `TokenVerifier`, `Scopes`, `StrongAuth` | `App\Auth\JwtVerifier`, `OAuthGuard::extractScopes()`, `isStrongAuth()` / `Helper::isStrongAuthClaims()` |
| `Guard` – auth driver `keycloak` | `App\Auth\OAuthGuard` + `ApiClient`, `overtrue/laravel-keycloak-guard` |
| `Client` – `ClientCredentials`, `ServiceClient` | `Helper::retrieveOAuthClientAccessToken()`, `Helper::queryGraphQLEndpoint()` |
| `Sso` – `OidcSessionRegistry`, back-channel logout | `OidcSession` model/migration, `SSOController::rememberOidcSession()`, `handleBackchannelLogout()` |
| `Admin` – user directory over the Admin REST API | `keycloak:*` commands' hand-written HTTP calls |
| `ThaiName` | name sync in `SSOController::callback()`, `splitFullName()`, `Helper::checkEncodingFault()` |
| `Mcp` – auth driver `keycloak-mcp`, RFC 9728 metadata | `App\Auth\McpOAuthGuard`, `ProtectedResourceMetadataController` |
| `Testing` – `FakeKeycloak`, `FakeUserDirectory` | RSA key / JWKS / signed-token setup copied into test files |

Requires PHP 8.3+ and Laravel 13.

## Installation

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/keenthekeen/oauth-helper.git"}
],
```

```bash
composer require keenthekeen/oauth-helper
php artisan vendor:publish --tag=oauth-helper-config   # optional
```

### Environment

```dotenv
KEYCLOAK_BASE_URL=https://acad-auth.md.chula.ac.th
KEYCLOAK_REALM=...
KEYCLOAK_CLIENT_ID=...            # this app's client
KEYCLOAK_CLIENT_SECRET=...
KEYCLOAK_API_AUDIENCE=...         # defaults to KEYCLOAK_CLIENT_ID
KEYCLOAK_ADMIN_CLIENT_ID=...      # only for the user directory, see below
KEYCLOAK_ADMIN_CLIENT_SECRET=...
KEYCLOAK_SSO_SESSION_MAX=36000    # the realm's SSO Session Max, in seconds
```

The issuer, JWKS, token and Admin API URLs are derived from the base URL and realm.

## API guard

```php
// config/auth.php
'guards' => [
    'api' => ['driver' => 'keycloak'],
],
```

```php
Gate::define('students_basic', fn ($user) => $user instanceof ApiClient && $user->hasScope('students_basic'));
```

An access token is accepted when it is signed by the realm, unexpired, and its `aud` contains `KEYCLOAK_API_AUDIENCE`,
or `APP_URL` or a URL under it (RFC 8707 resource indicators). Tokens for the MCP server's URL are refused. An invalid
token leaves the request unauthenticated (401) instead of throwing.

## Calling other services

```php
use Keenthekeen\OAuthHelper\Facades\Keycloak;

// Token cached per client, scope and resource until shortly before it expires
$token = Keycloak::token('students_exam', resource: config('services.precede.url'));

// Pending request with the token, JSON headers and the configured timeouts
$response = Keycloak::http('students_basic')->post(config('services.coop.url').'/graphql', [...]);

// GraphQL shortcut
$response = Keycloak::graphql(config('services.coop.url').'/graphql', $query, ['first' => 100], scope: 'students_basic');
$users = $response->json('data.users');
```

`token()` throws `TokenRequestException` when Keycloak refuses, and `ConnectionException` when it cannot be reached.
Add more clients under `oauth-helper.clients` and pass `client: 'name'`.

## Single sign-on

The login flow stays a Socialite driver (`oidc_keycloak` from `socialiteproviders/openidconnect`) and the callback
stays in the app, since user provisioning differs per app. The package handles what is around it:

```php
use Keenthekeen\OAuthHelper\Sso\OidcSessionRegistry;
use Keenthekeen\OAuthHelper\ThaiName\ThaiName;
use Keenthekeen\OAuthHelper\Token\StrongAuth;

public function callback(Request $request, OidcSessionRegistry $sessions) {
    $providerUser = Socialite::driver('oidc_keycloak')->user();
    $claims = $providerUser->user; // claims of the verified id_token

    $user = User::firstOrCreate(['email' => $providerUser->getEmail()], ['name' => $providerUser->getName()]);
    if ($name = ThaiName::preferredName($user->name, $claims)) {
        $user->update(['name' => $name]);
    }
    Auth::login($user);

    $sessions->remember($request, $user, $claims, $providerUser->accessTokenResponseBody['id_token'] ?? null);
    $request->session()->put('sso-acr-strong', StrongAuth::fromClaims($claims));
    // ...
}
```

Logout (RP-initiated), e.g. from a `Logout` event listener, which runs before the session is invalidated:

```php
$idToken = app(OidcSessionRegistry::class)->forget($request);
return redirect(app(OidcSessionRegistry::class)->logoutUrl($idToken, url('/')));
```

Back-channel logout: register the route outside the `web` middleware group (Keycloak sends no cookies or CSRF token),
then set `https://app/auth/sso/backchannel-logout` as the client's *Backchannel logout URL* in Keycloak.

```php
// bootstrap/app.php → withRouting(then: fn () => Route::oidcBackchannelLogout())
Route::oidcBackchannelLogout(); // POST auth/sso/backchannel-logout, named sso.backchannel-logout
```

Migration: `php artisan vendor:publish --tag=oauth-helper-migrations`. Apps that already have `oidc_sessions` keyed by
`sid` (Coop, Rollcall) should switch the primary key to `session_id` instead, so that signing in on several domains
with the same SSO session keeps one row per local session. The rows are short-lived, so dropping and recreating the
table is acceptable: only sessions started before the deploy lose back-channel logout.

## User directory (Keycloak Admin REST API)

Create a **separate** confidential client for each app (e.g. `precede-directory`) with *Service accounts* enabled, and
give its service account only `realm-management` → `view-users`. Do not reuse the login client, and never grant
`manage-users` for searching.

```php
use Keenthekeen\OAuthHelper\Admin\SearchMode;

$users = Keycloak::users()->search('somchai');                    // prefix match on username, email, first/last name
$users = Keycloak::users()->search('jaidee', SearchMode::Infix, max: 20);
$user = Keycloak::users()->findByUsername('6430012345');
$user = Keycloak::users()->findByEmail('someone@chula.ac.th');
$users = Keycloak::users()->findByAttribute('studentId', '6430012345');
$count = Keycloak::users()->count('somchai');

$user->email; $user->attribute('studentId'); $user->thaiName()?->full();
```

- Wildcards and quotes typed by users are stripped; the matching is chosen with `SearchMode` only.
- Queries shorter than `admin.min_query_length` throw `InvalidArgumentException`; results are capped at `admin.max_results`.
- Only attributes listed in `admin.attributes` leave the package.
- Results are cached for `admin.cache_ttl` seconds (0 disables).
- `AdminApiException` is thrown when Keycloak refuses (e.g. a missing role) or cannot be reached.

Results are personal data. In each app, authorize searches with a Gate, rate-limit them, log who searched for what,
and return only the fields the caller needs.

Keycloak's `search` matches only username, email, first and last name, not custom attributes such as the Thai name.
Check against your realm whether a Thai-name search finds anyone before offering it.

## Thai names

```php
ThaiName::split('สมศรี ณ อยุธยา');            // first: สมศรี, last: ณ อยุธยา
ThaiName::fromClaims($claims)?->full();       // from the thai_names client scope, null if missing or corrupted
ThaiName::preferredName($user->name, $claims); // name to save on login, or null to keep the stored one
ThaiName::hasEncodingFault($text);             // U+FFFD in a wrongly encoded CUNET name
```

Claim and attribute names are configured in `oauth-helper.thai_name` (`first_name`, `last_name` by default).

## MCP server

For an app serving an MCP server with `laravel/mcp`, where MCP clients sign users in with Keycloak:

```php
// config/auth.php
'guards' => ['mcp' => ['driver' => 'keycloak-mcp']],

// routes/ai.php
Route::mcpProtectedResource(); // /.well-known/oauth-protected-resource/{path?}, advertised in Laravel MCP's 401s
Mcp::web('/mcp', AppServer::class)->middleware(['auth:mcp', 'throttle:60,1']);
```

Tokens must carry the MCP server's URL (`MCP_RESOURCE_URL`, default `APP_URL/mcp`) in `aud` and the
`MCP_REQUIRED_SCOPE` scope (default `mcp:resources`). The user is looked up by the `email` claim in the configured
user model; use `McpGuard::resolveUsersUsing(fn (array $claims) => ...)` for anything else.
`Auth::guard('mcp')->isStrongAuth()` tells whether the user signed in to the MCP client with strong authentication.

In Keycloak, add an audience mapper (or resource indicator support) to the MCP client so its tokens carry the MCP
server's URL, and create the `mcp:resources` client scope with consent required.

## Testing

```php
use Keenthekeen\OAuthHelper\Testing\FakeKeycloak;

beforeEach(fn () => $this->keycloak = FakeKeycloak::install());

it('serves another service', function () {
    $this->withToken($this->keycloak->apiToken('reporting', ['students_basic']))
        ->postJson('/graphql', ['query' => '{ me { id } }'])
        ->assertOk();
});

it('serves an MCP client', function () {
    $this->withToken($this->keycloak->mcpToken($user->email, strongAuth: true))->postJson('/mcp', [...]);
});

it('searches users', function () {
    $directory = $this->keycloak->fakeUsers([['username' => 'somchai', 'email' => 'somchai@chula.ac.th']]);
    // ...
    $directory->assertSearched('somchai');
});

// Outgoing calls: answer client credentials grants
$this->keycloak->fakeTokenEndpoint();
```

## Development

```bash
composer install
composer test   # Pest on Orchestra Testbench
composer lint   # Pint
```
