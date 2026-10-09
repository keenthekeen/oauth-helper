# keenthekeen/oauth-helper

OAuth 2.0 / OpenID Connect helpers for Laravel apps that sign users in with, and call each other through, a shared
authorization server. They work with any standard server; only the user directory is Keycloak-specific, and endpoint
defaults follow Keycloak's layout.

Names follow one rule: anything that works with any OAuth server says *OAuth* (or nothing); only code calling
Keycloak-only APIs says *Keycloak* (`Directory\Keycloak\*`).

| Module | Provides |
|---|---|
| `Token` | `TokenVerifier` (JWKS signature, time claims, issuer, audience), `Scopes`, `StrongAuth` |
| `Guard` | Auth driver `oauth`: other services calling this app's API with client-credentials access tokens |
| `Client` | `ClientCredentials` (cached tokens per client, scope and RFC 8707 resource), `ServiceClient` (HTTP/GraphQL calls) |
| `Sso` | Optional. SSO session tracking, OIDC back-channel logout endpoint, RP-initiated logout URL |
| `Directory` | `UserDirectory` user search, implemented over the Keycloak Admin REST API |
| `ThaiName` | Splitting, claims parsing and login name-sync for Thai first and last names |
| `Mcp` | Auth driver `oauth-mcp` for user-delegated MCP tokens, RFC 9728 protected resource metadata |
| `Testing` | `FakeOAuthServer` (signed tokens, token endpoint fake), `FakeUserDirectory` |

Requires PHP 8.3+ and Laravel 13.

## Installation

```bash
composer require keenthekeen/oauth-helper
php artisan vendor:publish --tag=oauth-helper-config   # optional
```

### Environment

```dotenv
OAUTH_ISSUER=https://sso.example.com/realms/main   # `iss` of the server's tokens
OAUTH_CLIENT_ID=...                  # this app's client
OAUTH_CLIENT_SECRET=...
OAUTH_API_AUDIENCE=...               # defaults to OAUTH_CLIENT_ID
OAUTH_SSO_SESSION_MAX=36000          # longest SSO session (Keycloak: SSO Session Max), in seconds
```

`OAUTH_JWKS_URL` and `OAUTH_TOKEN_ENDPOINT` default to Keycloak's layout under the issuer
(`{issuer}/protocol/openid-connect/certs` and `/token`); set them for other servers. The directory's admin URL is
derived from a Keycloak issuer (`{base}/realms/{realm}` → `{base}/admin/realms/{realm}`) unless `KEYCLOAK_ADMIN_URL`
is set.

To keep existing variable names, publish the config and read them there.

## API guard

```php
// config/auth.php
'guards' => [
    'api' => ['driver' => 'oauth'],
],
```

```php
Gate::define('students_basic', fn ($user) => $user instanceof ApiClient && $user->hasScope('students_basic'));
```

An access token is accepted when it is signed by the server, unexpired, and its `aud` contains `OAUTH_API_AUDIENCE`,
or `APP_URL` or a URL under it (RFC 8707 resource indicators). Tokens for the MCP server's URL are refused. An invalid
token leaves the request unauthenticated (401) instead of throwing.

## Calling other services

```php
use Keenthekeen\OAuthHelper\Facades\OAuth;

// Token cached per client, scope and resource until shortly before it expires
$token = OAuth::token('orders:read', resource: 'https://orders.example.com');

// Pending request with the token, JSON headers and the configured timeouts
$response = OAuth::http('customers:read')->get('https://crm.example.com/api/customers');

// GraphQL shortcut
$response = OAuth::graphql('https://crm.example.com/graphql', $query, ['first' => 100], scope: 'customers:read');
$users = $response->json('data.users');
```

`token()` throws `TokenRequestException` when the server refuses, and `ConnectionException` when it cannot be reached.
Add more clients under `oauth-helper.clients` and pass `client: 'name'`.

## Single sign-on

This module is optional: apps with working session tracking and back-channel logout of their own can keep it.

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

Back-channel logout: register the route outside the `web` middleware group (the server sends no cookies or CSRF token),
then set `https://app/auth/sso/backchannel-logout` as the client's back-channel logout URL (Keycloak: *Backchannel logout URL*).

```php
// bootstrap/app.php → withRouting(then: fn () => Route::oidcBackchannelLogout())
Route::oidcBackchannelLogout(); // POST auth/sso/backchannel-logout, named sso.backchannel-logout
```

Migration: `php artisan vendor:publish --tag=oauth-helper-migrations`. The `oidc_sessions` table is keyed by the local
session ID, not by `sid`: an app served on several domains gets one local session per domain for the same SSO session,
and back-channel logout must destroy all of them.

## User directory

`UserDirectory` is the contract; `Directory\Keycloak\KeycloakUserDirectory` implements it over the Keycloak Admin
REST API and is bound by default. Another server can be supported by binding another implementation.

The directory authenticates as the app's own client (`OAUTH_CLIENT_ID`). For Keycloak, enable *Service accounts* on it
and give its service account `realm-management` → `view-users`; never grant `manage-users` for searching. Note that
anyone holding that client's secret can then read the whole realm's users. To limit that, add a separate client under
`oauth-helper.clients` with only this role and select it with `oauth-helper.directory.client`.

```php
use Keenthekeen\OAuthHelper\Directory\SearchMode;

$users = OAuth::users()->search('somchai');                    // prefix match on username, email, first/last name
$users = OAuth::users()->search('jaidee', SearchMode::Infix, max: 20);
$user = OAuth::users()->findByUsername('jdoe');
$user = OAuth::users()->findByEmail('jdoe@example.com');
$users = OAuth::users()->findByAttribute('employeeId', 'E12345');
$count = OAuth::users()->count('somchai');

$user->email; $user->attribute('employeeId'); $user->thaiName()?->full();
```

- Wildcards and quotes typed by users are stripped; the matching is chosen with `SearchMode` only.
- Queries shorter than `directory.min_query_length` throw `InvalidArgumentException`; results are capped at `directory.max_results`.
- Only attributes listed in `directory.attributes` leave the package (add e.g. `employeeId` to read it).
- Results are cached for `directory.cache_ttl` seconds (0 disables).
- `DirectoryException` is thrown when the directory refuses (e.g. a missing role) or cannot be reached.

Results are personal data. In each app, authorize searches with a Gate, rate-limit them, log who searched for what,
and return only the fields the caller needs.

Keycloak's `search` matches only username, email, first and last name, not custom attributes such as the Thai name.
Check against your Keycloak realm whether a Thai-name search finds anyone before offering it.

## Thai names

```php
ThaiName::split('สมศรี ณ อยุธยา');            // first: สมศรี, last: ณ อยุธยา
ThaiName::fromClaims($claims)?->full();       // from the thai_names client scope, null if missing or corrupted
ThaiName::preferredName($user->name, $claims); // name to save on login, or null to keep the stored one
ThaiName::hasEncodingFault($text);             // U+FFFD in a wrongly encoded name from an upstream directory
```

Claim and attribute names are configured in `oauth-helper.thai_name` (`first_name`, `last_name` by default).

## MCP server

For an app serving an MCP server with `laravel/mcp`, where MCP clients sign users in with the authorization server:

```php
// config/auth.php
'guards' => ['mcp' => ['driver' => 'oauth-mcp']],

// routes/ai.php
Route::mcpProtectedResource(); // /.well-known/oauth-protected-resource/{path?}, advertised in Laravel MCP's 401s
Mcp::web('/mcp', AppServer::class)->middleware(['auth:mcp', 'throttle:60,1']);
```

Tokens must carry the MCP server's URL (`MCP_RESOURCE_URL`, default `APP_URL/mcp`) in `aud` and the
`MCP_REQUIRED_SCOPE` scope (default `mcp:resources`). The user is looked up by the `email` claim in the configured
user model; use `McpGuard::resolveUsersUsing(fn (array $claims) => ...)` for anything else.
`Auth::guard('mcp')->isStrongAuth()` tells whether the user signed in to the MCP client with strong authentication.

On the server, make the MCP client's tokens carry the MCP server's URL in `aud` (in Keycloak: an audience mapper,
or resource indicator support), and create the `mcp:resources` client scope with consent required.

## Testing

```php
use Keenthekeen\OAuthHelper\Testing\FakeOAuthServer;

beforeEach(fn () => $this->server = FakeOAuthServer::install());

it('serves another service', function () {
    $this->withToken($this->server->apiToken('reporting', ['students_basic']))
        ->postJson('/graphql', ['query' => '{ me { id } }'])
        ->assertOk();
});

it('serves an MCP client', function () {
    $this->withToken($this->server->mcpToken($user->email, strongAuth: true))->postJson('/mcp', [...]);
});

it('searches users', function () {
    $directory = $this->server->fakeUsers([['username' => 'somchai', 'email' => 'somchai@example.com']]);
    // ...
    $directory->assertSearched('somchai');
});

// Outgoing calls: answer client credentials grants
$this->server->fakeTokenEndpoint();
```

## Development

```bash
composer install
composer test   # Pest on Orchestra Testbench
composer lint   # Pint
```
