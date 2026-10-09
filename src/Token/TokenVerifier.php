<?php

namespace Keenthekeen\OAuthHelper\Token;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Keenthekeen\OAuthHelper\Exceptions\InvalidTokenException;
use Keenthekeen\OAuthHelper\Realm;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * Verifies Keycloak-issued JWTs: signature against the realm's JWKS, time claims, issuer and audience.
 */
class TokenVerifier {
    /**
     * @param  array{keys: array<int, array<string, mixed>>}|null  $jwks  Fixed signing keys; when null, the realm's JWKS is fetched and cached
     */
    public function __construct(
        protected Realm $realm,
        protected CacheItemPoolInterface $cache,
        protected int $leeway = 10,
        protected int $jwksCacheTtl = 600,
        protected array $httpOptions = [],
        protected ?array $jwks = null,
    ) {}

    /**
     * Decode the token and validate its signature, time claims and issuer.
     * The audience is validated only when given: pass a string, or a predicate for audiences that are
     * matched by rule (any audience in the token passing it is enough).
     *
     * @param  string|callable(string): bool|null  $audience
     * @return array<string, mixed> The token's claims
     *
     * @throws InvalidTokenException
     */
    public function verify(string $jwt, string|callable|null $audience = null): array {
        $claims = $this->decode($jwt);

        if (($claims['iss'] ?? null) !== $this->realm->issuer()) {
            throw new InvalidTokenException('Invalid token issuer.');
        }

        if ($audience !== null) {
            $matches = is_string($audience) ? fn (string $aud) => $aud === $audience : $audience;
            if (! collect(self::audiences($claims))->contains(fn (string $aud) => $matches($aud))) {
                throw new InvalidTokenException('Invalid token audience.');
            }
        }

        return $claims;
    }

    /**
     * Decode the token and validate its signature and time claims only.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidTokenException
     */
    public function decode(string $jwt): array {
        JWT::$leeway = $this->leeway;

        try {
            $decoded = JWT::decode($jwt, $this->keySet());
        } catch (Throwable $e) {
            // Firebase JWT throws UnexpectedValueException, DomainException, InvalidArgumentException and more
            throw new InvalidTokenException('Invalid access token: '.$e->getMessage(), previous: $e);
        }

        return json_decode(json_encode($decoded), true);
    }

    /**
     * The `aud` claim as a list of strings.
     *
     * @param  array<string, mixed>  $claims
     * @return array<int, string>
     */
    public static function audiences(array $claims): array {
        $aud = $claims['aud'] ?? [];

        return array_values(array_filter(is_array($aud) ? $aud : [$aud], 'is_string'));
    }

    /**
     * @return CachedKeySet|array<string, Key>
     */
    protected function keySet(): CachedKeySet|array {
        if ($this->jwks !== null) {
            return JWK::parseKeySet($this->jwks);
        }

        return new CachedKeySet(
            jwksUri: $this->realm->jwksUrl(),
            httpClient: new Client($this->httpOptions),
            httpFactory: new HttpFactory,
            cache: $this->cache,
            expiresAfter: $this->jwksCacheTtl,
            rateLimit: true,
        );
    }
}
