<?php

namespace Keenthekeen\OAuthHelper\Token;

class StrongAuth {
    /**
     * Did the user sign in with strong (multi-factor) authentication, according to the id_token or access token
     * claims: a strong `acr`, a strong method in `amr`, or an identity provider that enforces its own MFA?
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims): bool {
        $config = config('oauth-helper.strong_auth');

        return in_array($claims['acr'] ?? null, $config['acr'], true)
            or in_array($claims['identity_provider'] ?? null, $config['identity_providers'], true)
            or array_intersect((array) ($claims['amr'] ?? []), $config['amr']) !== [];
    }
}
