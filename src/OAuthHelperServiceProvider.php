<?php

namespace Keenthekeen\OAuthHelper;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Keenthekeen\OAuthHelper\Client\ClientCredentials;
use Keenthekeen\OAuthHelper\Client\ServiceClient;
use Keenthekeen\OAuthHelper\Directory\Keycloak\KeycloakAdminClient;
use Keenthekeen\OAuthHelper\Directory\Keycloak\KeycloakUserDirectory;
use Keenthekeen\OAuthHelper\Directory\UserDirectory;
use Keenthekeen\OAuthHelper\Guard\ApiAudience;
use Keenthekeen\OAuthHelper\Guard\BearerGuard;
use Keenthekeen\OAuthHelper\Mcp\McpGuard;
use Keenthekeen\OAuthHelper\Mcp\ProtectedResourceMetadataController;
use Keenthekeen\OAuthHelper\Sso\BackchannelLogoutController;
use Keenthekeen\OAuthHelper\Sso\OidcSessionRegistry;
use Keenthekeen\OAuthHelper\Token\TokenVerifier;

class OAuthHelperServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/oauth-helper.php', 'oauth-helper');

        $this->app->bind(AuthorizationServer::class, fn () => AuthorizationServer::fromConfig());

        $this->app->bind(TokenVerifier::class, fn (Application $app) => new TokenVerifier(
            server: $app->make(AuthorizationServer::class),
            cache: $app->make('cache.psr6'),
            leeway: (int) config('oauth-helper.leeway'),
            jwksCacheTtl: (int) config('oauth-helper.jwks_cache_ttl'),
            httpOptions: (array) config('oauth-helper.http'),
        ));

        $this->app->bind(ClientCredentials::class, fn (Application $app) => new ClientCredentials(
            server: $app->make(AuthorizationServer::class),
            http: $app->make(Http::class),
            cache: $app->make('cache.store'),
            clients: (array) config('oauth-helper.clients'),
            httpOptions: (array) config('oauth-helper.http'),
        ));

        $this->app->bind(ServiceClient::class, fn (Application $app) => new ServiceClient(
            credentials: $app->make(ClientCredentials::class),
            http: $app->make(Http::class),
            httpOptions: (array) config('oauth-helper.http'),
        ));

        $this->app->bind(KeycloakAdminClient::class, fn (Application $app) => new KeycloakAdminClient(
            adminUrl: config('oauth-helper.directory.keycloak_admin_url')
                ?: KeycloakAdminClient::adminUrlFromIssuer($app->make(AuthorizationServer::class)->issuer()),
            credentials: $app->make(ClientCredentials::class),
            http: $app->make(Http::class),
            client: (string) config('oauth-helper.directory.client'),
            httpOptions: ['timeout' => config('oauth-helper.directory.timeout')] + (array) config('oauth-helper.http'),
        ));

        $this->app->bind(UserDirectory::class, fn (Application $app) => new KeycloakUserDirectory(
            client: $app->make(KeycloakAdminClient::class),
            cache: $app->make('cache.store'),
            maxResults: (int) config('oauth-helper.directory.max_results'),
            minQueryLength: (int) config('oauth-helper.directory.min_query_length'),
            cacheTtl: (int) config('oauth-helper.directory.cache_ttl'),
            attributes: (array) config('oauth-helper.directory.attributes'),
        ));

        $this->app->bind(OidcSessionRegistry::class, fn () => new OidcSessionRegistry(
            sessionMax: (int) config('oauth-helper.sso.session_max'),
            idTokenSessionKey: (string) config('oauth-helper.sso.id_token_session_key'),
        ));

        $this->app->singleton(OAuth::class);
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/oauth-helper.php' => config_path('oauth-helper.php'),
            ], 'oauth-helper-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_oidc_sessions_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_oidc_sessions_table.php'),
            ], 'oauth-helper-migrations');
        }

        $this->registerGuards();
        $this->registerRoutes();
    }

    protected function registerGuards(): void {
        Auth::extend('oauth', function (Application $app) {
            $guard = new BearerGuard($app['request'], $app->make(TokenVerifier::class), ApiAudience::matches(...));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        Auth::extend('oauth-mcp', function (Application $app) {
            $guard = new McpGuard($app['request'], $app->make(TokenVerifier::class));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }

    protected function registerRoutes(): void {
        /*
         * Back-channel logout endpoint (OpenID Connect Back-Channel Logout 1.0). Register it outside the web
         * middleware group: the server sends no cookies or CSRF token. With Keycloak, set the URL as the
         * client's "Backchannel logout URL".
         */
        Route::macro('oidcBackchannelLogout', function (string $uri = 'auth/sso/backchannel-logout') {
            return Route::post($uri, BackchannelLogoutController::class)->name('sso.backchannel-logout');
        });

        /*
         * OAuth 2.0 Protected Resource Metadata (RFC 9728) for the MCP server. Laravel MCP finds the route by
         * name and advertises it in the WWW-Authenticate header of its 401 responses.
         */
        Route::macro('mcpProtectedResource', function () {
            return Route::get('/.well-known/oauth-protected-resource/{path?}', ProtectedResourceMetadataController::class)
                ->where('path', '.*')
                ->name('mcp.oauth.protected-resource.nested');
        });
    }
}
