<?php

namespace Keenthekeen\OAuthHelper\Token;

class Scopes {
    /**
     * Granted scopes from the `scope` (space-delimited) and `scp` (array) claims, sorted and unique.
     *
     * @param  array<string, mixed>  $claims
     * @return array<int, string>
     */
    public static function fromClaims(array $claims): array {
        $scopes = [];

        if (isset($claims['scope']) && is_string($claims['scope'])) {
            $scopes = preg_split('/\s+/', trim($claims['scope']), -1, PREG_SPLIT_NO_EMPTY);
        }

        if (isset($claims['scp']) && is_array($claims['scp'])) {
            $scopes = array_merge($scopes, array_filter($claims['scp'], 'is_string'));
        }

        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        return $scopes;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<int, string>  $scopes
     */
    public static function hasAny(array $claims, array $scopes): bool {
        return array_intersect(self::fromClaims($claims), $scopes) !== [];
    }
}
