<?php

namespace Keenthekeen\OAuthHelper\Testing;

use Firebase\JWT\JWT;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Http;
use Keenthekeen\OAuthHelper\Contracts\UserDirectory;
use Keenthekeen\OAuthHelper\Mcp\McpResource;
use Keenthekeen\OAuthHelper\Realm;
use Keenthekeen\OAuthHelper\Token\TokenVerifier;

/**
 * A stand-in Keycloak realm for tests: signs tokens with a throwaway key the app trusts,
 * fakes the token endpoint, and swaps the user directory for an in-memory one.
 *
 *     $keycloak = FakeKeycloak::install();
 *     $this->withToken($keycloak->apiToken(scopes: ['students_basic']))->postJson('/graphql', ...);
 */
class FakeKeycloak {
    public const ISSUER = 'https://keycloak.test/realms/test';

    protected string $privateKey;

    /** @var array{keys: array<int, array<string, string>>} */
    protected array $jwks;

    public function __construct() {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $this->privateKey = $privateKey;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'test-key',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ]]];
    }

    /**
     * Point the app at the fake realm and trust its signing key.
     */
    public static function install(): self {
        $fake = new self;
        $app = Container::getInstance();

        config([
            'oauth-helper.base_url' => 'https://keycloak.test',
            'oauth-helper.realm' => 'test',
            'oauth-helper.issuer' => null,
        ]);

        $app->bind(TokenVerifier::class, fn ($app) => new TokenVerifier(
            realm: $app->make(Realm::class),
            cache: $app->make('cache.psr6'),
            leeway: (int) config('oauth-helper.leeway'),
            jwks: $fake->jwks,
        ));

        return $fake;
    }

    /**
     * Sign a token with the fake realm's key. Defaults to a valid, unexpired token from the fake issuer.
     *
     * @param  array<string, mixed>  $claims
     */
    public function token(array $claims = []): string {
        return JWT::encode([
            'iss' => self::ISSUER,
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => bin2hex(random_bytes(8)),
            ...$claims,
        ], $this->privateKey, 'RS256', 'test-key');
    }

    /**
     * A client-credentials access token for this app's API, as another service would send it.
     *
     * @param  array<int, string>  $scopes
     * @param  array<string, mixed>  $claims
     */
    public function apiToken(string $clientId = 'test-service', array $scopes = [], array $claims = []): string {
        return $this->token([
            'aud' => [config('oauth-helper.api.audience') ?? config('app.url')],
            'azp' => $clientId,
            'scope' => implode(' ', $scopes),
            ...$claims,
        ]);
    }

    /**
     * A user-delegated access token for the MCP server, as an MCP client would send it.
     *
     * @param  array<string, mixed>  $claims
     */
    public function mcpToken(string $email, bool $strongAuth = false, array $claims = []): string {
        return $this->token([
            'aud' => [McpResource::url()],
            'azp' => 'mcp-client',
            'sub' => 'user-'.md5($email),
            'email' => $email,
            'scope' => 'openid '.McpResource::scope(),
            ...($strongAuth ? ['acr' => 'strong'] : []),
            ...$claims,
        ]);
    }

    /**
     * Answer client credentials grants with fresh tokens. Assert on them with Http::assertSent().
     */
    public function fakeTokenEndpoint(int $expiresIn = 300): self {
        Http::fake([
            app(Realm::class)->tokenEndpoint() => fn () => Http::response([
                'access_token' => $this->token(['azp' => 'this-app']),
                'token_type' => 'Bearer',
                'expires_in' => $expiresIn,
            ]),
        ]);

        return $this;
    }

    /**
     * Replace the user directory with an in-memory one.
     *
     * @param  array<int, array<string, mixed>>  $users  UserRepresentations, as the Admin API returns them
     */
    public function fakeUsers(array $users): FakeUserDirectory {
        $directory = new FakeUserDirectory($users, (array) config('oauth-helper.admin.attributes'), (int) config('oauth-helper.admin.min_query_length'));
        Container::getInstance()->instance(UserDirectory::class, $directory);

        return $directory;
    }
}
