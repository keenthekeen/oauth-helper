<?php

namespace Keenthekeen\OAuthHelper\Guard;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A client (service) authenticated with an access token, identified by its `azp` client ID.
 */
class ApiClient implements Authenticatable {
    /**
     * @param  array<string, mixed>  $claims
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        protected string $clientId,
        protected array $claims = [],
        protected array $scopes = [],
        protected ?string $token = null,
    ) {}

    public function getAuthIdentifierName(): string {
        return 'client_id';
    }

    public function getAuthIdentifier(): string {
        return $this->clientId;
    }

    public function getAuthPasswordName(): string {
        return 'password';
    }

    public function getAuthPassword(): ?string {
        return null;
    }

    public function getRememberToken(): ?string {
        return null;
    }

    public function setRememberToken($value): void {
        // Not applicable
    }

    public function getRememberTokenName(): string {
        return 'remember_token';
    }

    public function token(): ?string {
        return $this->token;
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array {
        return $this->claims;
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function hasAnyScope(array $scopes): bool {
        return array_intersect($this->scopes, $scopes) !== [];
    }
}
