<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Keycloak realm
    |--------------------------------------------------------------------------
    |
    | The issuer, JWKS, token and Admin API URLs are derived from the base URL
    | and realm. Set KEYCLOAK_ISSUER only if the issuer differs from
    | "{base_url}/realms/{realm}", e.g. behind a reverse proxy.
    |
    */

    'base_url' => env('KEYCLOAK_BASE_URL', 'https://acad-auth.md.chula.ac.th'),

    'realm' => env('KEYCLOAK_REALM'),

    'issuer' => env('KEYCLOAK_ISSUER'),

    // Seconds of clock skew allowed when validating exp/nbf/iat of access tokens
    'leeway' => (int) env('KEYCLOAK_LEEWAY', 10),

    // How long the realm's signing keys (JWKS) are cached, in seconds
    'jwks_cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 600),

    // Guzzle options for every call to Keycloak and to other services (timeout, proxy, verify...)
    'http' => [
        'timeout' => 20,
        'connect_timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidential clients
    |--------------------------------------------------------------------------
    |
    | Clients this app authenticates as with the client credentials grant.
    | "default" is the app's own client, used for calls to other services.
    | "admin" is a separate client holding only the realm-management
    | "view-users" service-account role, used by the user directory. Keep it
    | separate so the login client's secret cannot read the whole directory.
    |
    */

    'clients' => [
        'default' => [
            'client_id' => env('KEYCLOAK_CLIENT_ID'),
            'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        ],
        'admin' => [
            'client_id' => env('KEYCLOAK_ADMIN_CLIENT_ID'),
            'client_secret' => env('KEYCLOAK_ADMIN_CLIENT_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API bearer-token guard (auth driver "keycloak")
    |--------------------------------------------------------------------------
    |
    | Access tokens must carry the API audience in `aud` (add an audience mapper
    | to each calling client in Keycloak). With accept_app_url, APP_URL or a URL
    | under it is accepted too (RFC 8707 resource indicators), except the MCP
    | server's URL, whose tokens are only for MCP clients.
    |
    */

    'api' => [
        'audience' => env('KEYCLOAK_API_AUDIENCE', env('KEYCLOAK_CLIENT_ID')),
        'accept_app_url' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Single sign-on
    |--------------------------------------------------------------------------
    |
    | The login flow itself is configured as a Socialite driver (config/oidc.php
    | of socialiteproviders/openidconnect). These settings cover what happens
    | around it: tracking SSO sessions for back-channel logout, and deciding
    | whether a login used strong (multi-factor) authentication.
    |
    */

    'sso' => [
        'driver' => 'oidc_keycloak',

        // Longest an SSO session can live (seconds): the realm's SSO Session Max, or its Remember Me
        // max if larger. oidc_sessions rows older than this can no longer be logged out and are pruned.
        'session_max' => (int) env('KEYCLOAK_SSO_SESSION_MAX', 36000),

        // Session key holding the id_token, sent back to Keycloak as id_token_hint on logout
        'id_token_session_key' => 'oidc_id_token',

        'user_model' => 'App\\Models\\User',
    ],

    'strong_auth' => [
        'acr' => ['2', 'strong'],
        'amr' => ['idp', 'passkey', 'otp', 'webauthn', 'otp_register'],
        // Logins through these identity providers enforce their own multi-factor authentication
        'identity_providers' => ['google'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thai names
    |--------------------------------------------------------------------------
    |
    | Claim names (from the "thai_names" client scope) and user attribute names
    | holding the Thai first and last name.
    |
    */

    'thai_name' => [
        'first' => 'first_name',
        'last' => 'last_name',
    ],

    /*
    |--------------------------------------------------------------------------
    | User directory (Keycloak Admin REST API)
    |--------------------------------------------------------------------------
    */

    'admin' => [
        'client' => 'admin',
        'timeout' => 5,
        // Hard limit on results per search, whatever the caller asks for
        'max_results' => 50,
        // Shorter queries are refused, so the directory cannot be paged through
        'min_query_length' => 3,
        // Seconds search results are cached; 0 disables caching
        'cache_ttl' => 60,
        // User attributes exposed on KeycloakUser; all others are dropped
        'attributes' => ['studentId', 'first_name', 'last_name'],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server authorization (auth driver "keycloak-mcp")
    |--------------------------------------------------------------------------
    |
    | MCP clients sign users in with Keycloak and send user-delegated access
    | tokens bound to the MCP server's URL (RFC 8707). The resource defaults to
    | APP_URL/mcp. The user is looked up by the claim in the given column.
    |
    */

    'mcp' => [
        'resource' => env('MCP_RESOURCE_URL'),
        'scope' => env('MCP_REQUIRED_SCOPE', 'mcp:resources'),
        'user_model' => 'App\\Models\\User',
        'user_claim' => 'email',
        'user_column' => 'email',
    ],

];
