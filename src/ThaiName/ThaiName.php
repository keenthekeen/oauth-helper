<?php

namespace Keenthekeen\OAuthHelper\ThaiName;

use Stringable;

/**
 * A Thai first and last name, as provided by the "thai_names" client scope or user attributes.
 */
final readonly class ThaiName implements Stringable {
    public function __construct(
        public string $first,
        public string $last,
    ) {}

    /**
     * The Thai name from id_token claims, if both parts are present and correctly encoded.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims): ?self {
        return self::fromArray($claims);
    }

    /**
     * @param  array<string, mixed>  $values  Claims or attributes, keyed by the configured first/last names
     */
    public static function fromArray(array $values): ?self {
        $first = $values[config('oauth-helper.thai_name.first')] ?? null;
        $last = $values[config('oauth-helper.thai_name.last')] ?? null;
        if (! is_string($first) or ! is_string($last)) {
            return null;
        }

        $name = new self(self::normalizeSpaces($first), self::normalizeSpaces($last));

        return ($name->first !== '' and $name->isValid()) ? $name : null;
    }

    /**
     * Split a full name into first and last name. The last word is the last name, except for
     * last names starting with "ณ" (e.g. "ณ อยุธยา"), where everything after the first word is.
     */
    public static function split(string $fullName): self {
        $parts = explode(' ', self::normalizeSpaces($fullName));

        if (count($parts) > 2 and in_array('ณ', $parts, true)) {
            return new self(array_shift($parts), implode(' ', $parts));
        }

        $last = count($parts) > 1 ? array_pop($parts) : '';

        return new self(implode(' ', $parts), $last);
    }

    /**
     * Does the text contain a replacement character (U+FFFD)? Some upstream directories hold wrongly encoded Thai names.
     */
    public static function hasEncodingFault(string $text): bool {
        return str_contains($text, "\u{FFFD}");
    }

    /**
     * The name to save for a user signing in, or null to keep the current one.
     *
     * Prefers the Thai name from the claims; a stored name containing both parts is kept, to preserve a title
     * or rank prefixed to it (e.g. "ผศ.นพ."). Without a Thai name, falls back to the display name (`name` claim).
     *
     * @param  array<string, mixed>  $claims  Claims of the verified id_token
     */
    public static function preferredName(?string $currentName, array $claims): ?string {
        $currentName = (string) $currentName;
        if ($thaiName = self::fromClaims($claims)) {
            return $thaiName->isContainedIn($currentName) ? null : $thaiName->full();
        }

        // A Thai name that is present but corrupted must not be replaced by the English display name either
        $first = $claims[config('oauth-helper.thai_name.first')] ?? null;
        if (is_string($first) and trim($first) !== '') {
            return null;
        }

        $displayName = isset($claims['name']) && is_string($claims['name']) ? self::normalizeSpaces($claims['name']) : '';

        return ($displayName !== '' and $displayName !== $currentName) ? $displayName : null;
    }

    public function full(): string {
        return trim($this->first.' '.$this->last);
    }

    public function isValid(): bool {
        return ! self::hasEncodingFault($this->first) and ! self::hasEncodingFault($this->last);
    }

    /**
     * Does the text contain both parts of the name, e.g. a stored name with a title before it?
     */
    public function isContainedIn(string $text): bool {
        return str_contains($text, $this->first) and str_contains($text, $this->last);
    }

    public function __toString(): string {
        return $this->full();
    }

    protected static function normalizeSpaces(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
