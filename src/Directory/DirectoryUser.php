<?php

namespace Keenthekeen\OAuthHelper\Directory;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Keenthekeen\OAuthHelper\ThaiName\ThaiName;

/**
 * A user from the user directory, with only the allowlisted attributes.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DirectoryUser implements Arrayable, JsonSerializable {
    /**
     * @param  array<string, array<int, string>>  $attributes
     */
    public function __construct(
        public string $id,
        public string $username,
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public bool $enabled = true,
        public bool $emailVerified = false,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $representation  Keycloak's UserRepresentation, or the same keys from another directory
     * @param  array<int, string>  $allowedAttributes
     */
    public static function fromRepresentation(array $representation, array $allowedAttributes = []): self {
        $attributes = array_intersect_key((array) ($representation['attributes'] ?? []), array_flip($allowedAttributes));

        return new self(
            id: (string) $representation['id'],
            username: (string) ($representation['username'] ?? ''),
            email: $representation['email'] ?? null,
            firstName: $representation['firstName'] ?? null,
            lastName: $representation['lastName'] ?? null,
            enabled: (bool) ($representation['enabled'] ?? true),
            emailVerified: (bool) ($representation['emailVerified'] ?? false),
            attributes: array_map(fn ($values) => array_values(array_map('strval', (array) $values)), $attributes),
        );
    }

    /**
     * First value of an attribute.
     */
    public function attribute(string $name): ?string {
        return $this->attributes[$name][0] ?? null;
    }

    public function name(): string {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * The Thai name from the user's attributes, if both parts are present and allowlisted.
     */
    public function thaiName(): ?ThaiName {
        return ThaiName::fromArray(array_map(fn ($values) => $values[0] ?? null, $this->attributes));
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'enabled' => $this->enabled,
            'emailVerified' => $this->emailVerified,
            'attributes' => $this->attributes,
        ];
    }

    public function jsonSerialize(): array {
        return $this->toArray();
    }
}
