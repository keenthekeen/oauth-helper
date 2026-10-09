<?php

namespace Keenthekeen\OAuthHelper;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Keenthekeen\OAuthHelper\Admin\AdminClient;
use Keenthekeen\OAuthHelper\Admin\KeycloakUserDirectory;
use Keenthekeen\OAuthHelper\Client\ClientCredentials;
use Keenthekeen\OAuthHelper\Client\ServiceClient;
use Keenthekeen\OAuthHelper\Contracts\UserDirectory;
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

        $this->app->bind(Realm::class, fn () => Realm::fromConfig());

        $this->app->bind(TokenVerifier::class, fn (Application $app) => new TokenVerifier(
            realm: $app->make(Realm::class),
            cache: $app->make('cache.psr6'),
            leeway: (int) config('oauth-helper.leeway'),
            jwksCacheTtl: (int) config('oauth-helper.jwks_cache_ttl'),
            httpOptions: (array) config('oauth-helper.http'),
        ));

        $this->app->bind(ClientCredentials::class, fn (Application $app) => new ClientCredentials(
            realm: $app->make(Realm::class),
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

        $this->app->bind(AdminClient::class, fn (Application $app) => new AdminClient(
            realm: $app->make(Realm::class),
            credentials: $app->make(ClientCredentials::class),
            http: $app->make(Http::class),
            client: (string) config('oauth-helper.admin.client'),
            httpOptions: ['timeout' => config('oauth-helper.admin.timeout')] + (array) config('oauth-helper.http'),
        ));

        $this->app->bind(UserDirectory::class, fn (Application $app) => new KeycloakUserDirectory(
            client: $app->make(AdminClient::class),
            cache: $app->make('cache.store'),
            maxResults: (int) config('oauth-helper.admin.max_results'),
            minQueryLength: (int) config('oauth-helper.admin.min_query_length'),
            cacheTtl: (int) config('oauth-helper.admin.cache_ttl'),
            attributes: (array) config('oauth-helper.admin.attributes'),
        ));

        $this->app->bind(OidcSessionRegistry::class, fn () => new OidcSessionRegistry(
            sessionMax: (int) config('oauth-helper.sso.session_max'),
            idTokenSessionKey: (string) config('oauth-helper.sso.id_token_session_key'),
        ));

        $this->app->singleton(Keycloak::class);
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
        Auth::extend('keycloak', function (Application $app) {
            $guard = new BearerGuard($app['request'], $app->make(TokenVerifier::class), ApiAudience::matches(...));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        Auth::extend('keycloak-mcp', function (Application $app) {
            $guard = new McpGuard($app['request'], $app->make(TokenVerifier::class));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }

    protected function registerRoutes(): void {
        /*
         * Keycloak's back-channel logout endpoint. Register it outside the web middleware group:
         * Keycloak sends no cookies or CSRF token. Set the URL as "Backchannel logout URL" of the client.
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
