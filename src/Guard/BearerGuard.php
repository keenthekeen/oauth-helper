<?php

namespace Keenthekeen\OAuthHelper\Guard;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Keenthekeen\OAuthHelper\Exceptions\InvalidTokenException;
use Keenthekeen\OAuthHelper\Token\Scopes;
use Keenthekeen\OAuthHelper\Token\TokenVerifier;

/**
 * Authenticates other services calling this app's API with OAuth access tokens (auth driver "oauth").
 * An invalid token, or one meant for another audience, leaves the request unauthenticated.
 */
class BearerGuard implements Guard {
    protected ?Authenticatable $user = null;

    /**
     * @param  Closure(string): bool  $audience  Whether an `aud` value is this API
     */
    public function __construct(
        protected Request $request,
        protected TokenVerifier $verifier,
        protected Closure $audience,
    ) {}

    public function check(): bool {
        return $this->user() !== null;
    }

    public function guest(): bool {
        return ! $this->check();
    }

    public function user(): ?Authenticatable {
        if ($this->user !== null) {
            return $this->user;
        }
        if (! $token = $this->request->bearerToken()) {
            return null;
        }

        try {
            $claims = $this->verifier->verifyAccessToken($token, $this->audience);
        } catch (InvalidTokenException) {
            return null;
        }

        // azp: the client that requested the token
        if (empty($claims['azp']) or ! is_string($claims['azp'])) {
            return null;
        }

        return $this->user = new ApiClient(
            clientId: $claims['azp'],
            claims: $claims,
            scopes: Scopes::fromClaims($claims),
            token: $token,
        );
    }

    public function id(): ?string {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool {
        return $this->user() !== null;
    }

    public function hasUser(): bool {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static {
        $this->user = $user;

        return $this;
    }

    public function setRequest(Request $request): static {
        $this->request = $request;

        return $this;
    }
}
