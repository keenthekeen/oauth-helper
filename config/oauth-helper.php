<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authorization server
    |--------------------------------------------------------------------------
    |
    | The issuer is the `iss` of its tokens, e.g. "https://sso.example.com/realms/main"
    | for a Keycloak realm. The JWKS and token endpoints default to Keycloak's
    | layout under the issuer; set them for other servers.
    |
    */

    'issuer' => env('OAUTH_ISSUER'),

    'jwks_url' => env('OAUTH_JWKS_URL'),

    'token_endpoint' => env('OAUTH_TOKEN_ENDPOINT'),

    // Seconds of clock skew allowed when validating exp/nbf/iat of access tokens
    'leeway' => (int) env('OAUTH_LEEWAY', 10),

    // How long the server's signing keys (JWKS) are cached, in seconds
    'jwks_cache_ttl' => (int) env('OAUTH_JWKS_CACHE_TTL', 600),

    // Guzzle options for every call to the authorization server and to other services (timeout, proxy, verify...)
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
    | "directory" is a separate client allowed only to read users, used by the
    | user directory (with Keycloak: the realm-management "view-users"
    | service-account role). Keep it separate so that the login client's
    | secret cannot read the whole directory.
    |
    */

    'clients' => [
        'default' => [
            'client_id' => env('OAUTH_CLIENT_ID'),
            'client_secret' => env('OAUTH_CLIENT_SECRET'),
        ],
        'directory' => [
            'client_id' => env('OAUTH_DIRECTORY_CLIENT_ID'),
            'client_secret' => env('OAUTH_DIRECTORY_CLIENT_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API bearer-token guard (auth driver "oauth")
    |--------------------------------------------------------------------------
    |
    | Access tokens must carry the API audience in `aud` (add an audience mapper
    | to each calling client on the authorization server). With accept_app_url, APP_URL or a URL
    | under it is accepted too (RFC 8707 resource indicators), except the MCP
    | server's URL, whose tokens are only for MCP clients.
    |
    */

    'api' => [
        'audience' => env('OAUTH_API_AUDIENCE', env('OAUTH_CLIENT_ID')),
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
        // Socialite driver of the login flow (a connection of socialiteproviders/openidconnect)
        'driver' => 'oidc_keycloak',

        // Longest an SSO session can live (seconds), e.g. Keycloak's SSO Session Max, or its Remember Me
        // max if larger. oidc_sessions rows older than this can no longer be logged out and are pruned.
        'session_max' => (int) env('OAUTH_SSO_SESSION_MAX', 36000),

        // Session key holding the id_token, sent back to the server as id_token_hint on logout
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
    | Claim names (e.g. from a "thai_names" client scope) and user attribute names
    | holding the Thai first and last name.
    |
    */

    'thai_name' => [
        'first' => 'first_name',
        'last' => 'last_name',
    ],

    /*
    |--------------------------------------------------------------------------
    | User directory
    |--------------------------------------------------------------------------
    |
    | Searches users through the Keycloak Admin REST API. The admin URL
    | defaults to the issuer's realm ("{base}/admin/realms/{realm}").
    |
    */

    'directory' => [
        'client' => 'directory',
        'keycloak_admin_url' => env('KEYCLOAK_ADMIN_URL'),
        'timeout' => 5,
        // Hard limit on results per search, whatever the caller asks for
        'max_results' => 50,
        // Shorter queries are refused, so the directory cannot be paged through
        'min_query_length' => 3,
        // Seconds search results are cached; 0 disables caching
        'cache_ttl' => 60,
        // User attributes exposed on DirectoryUser; all others are dropped
        'attributes' => ['first_name', 'last_name'],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server authorization (auth driver "oauth-mcp")
    |--------------------------------------------------------------------------
    |
    | MCP clients sign users in with the authorization server and send user-delegated access
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
