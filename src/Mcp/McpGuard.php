<?php

namespace Keenthekeen\OAuthHelper\Mcp;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Keenthekeen\OAuthHelper\Exceptions\InvalidTokenException;
use Keenthekeen\OAuthHelper\Token\Scopes;
use Keenthekeen\OAuthHelper\Token\StrongAuth;
use Keenthekeen\OAuthHelper\Token\TokenVerifier;

/**
 * Authenticates MCP clients with user-delegated OAuth access tokens (auth driver "oauth-mcp").
 *
 * Tokens must be bound to the MCP server's URL (RFC 8707 resource indicator), so tokens issued for other
 * clients of the authorization server are rejected, and carry the MCP scope the user consented to.
 */
class McpGuard implements Guard {
    /** @var (Closure(array<string, mixed>): ?Authenticatable)|null */
    protected static ?Closure $userResolver = null;

    protected ?Authenticatable $user = null;

    /** @var array<string, mixed> */
    protected array $claims = [];

    public function __construct(
        protected Request $request,
        protected TokenVerifier $verifier,
    ) {}

    /**
     * Resolve the user from the token's claims, instead of looking up the configured model by email.
     *
     * @param  (Closure(array<string, mixed>): ?Authenticatable)|null  $resolver
     */
    public static function resolveUsersUsing(?Closure $resolver): void {
        static::$userResolver = $resolver;
    }

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
            $claims = $this->verifier->verifyAccessToken($token, McpResource::url());
        } catch (InvalidTokenException) {
            return null;
        }

        // The user must have consented to MCP access; client-credentials tokens carry no user identity
        if (! in_array(McpResource::scope(), Scopes::fromClaims($claims), true)) {
            return null;
        }

        if (! $user = $this->resolveUser($claims)) {
            return null;
        }
        $this->claims = $claims;

        return $this->user = $user;
    }

    /**
     * Claims of the validated access token.
     *
     * @return array<string, mixed>
     */
    public function claims(): array {
        return $this->user() !== null ? $this->claims : [];
    }

    /**
     * Did the user sign in to the MCP client with strong authentication? Data that the web app only shows in
     * strong authentication sessions must require it over MCP too.
     */
    public function isStrongAuth(): bool {
        return $this->user() !== null and StrongAuth::fromClaims($this->claims);
    }

    public function id(): int|string|null {
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

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function resolveUser(array $claims): ?Authenticatable {
        if (static::$userResolver) {
            return (static::$userResolver)($claims);
        }

        $value = $claims[config('oauth-helper.mcp.user_claim')] ?? null;
        if (! is_string($value) or $value === '') {
            return null;
        }
        $model = config('oauth-helper.mcp.user_model');

        return $model::where(config('oauth-helper.mcp.user_column'), $value)->first();
    }
}
