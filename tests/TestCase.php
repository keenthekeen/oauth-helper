<?php

namespace Keenthekeen\OAuthHelper\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Keenthekeen\OAuthHelper\Mcp\McpGuard;
use Keenthekeen\OAuthHelper\OAuthHelperServiceProvider;
use Keenthekeen\OAuthHelper\Tests\Fixtures\User;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra {
    protected function getPackageProviders($app): array {
        return [SocialiteServiceProvider::class, OAuthHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void {
        $app['config']->set('app.url', 'https://app.test');
        $app['config']->set('oauth-helper.realm', 'test');
        $app['config']->set('oauth-helper.api.audience', 'test-app');
        $app['config']->set('oauth-helper.clients.default', ['client_id' => 'test-app', 'client_secret' => 'secret']);
        $app['config']->set('oauth-helper.clients.admin', ['client_id' => 'test-app-admin', 'client_secret' => 'admin-secret']);
        $app['config']->set('oauth-helper.sso.user_model', User::class);
        $app['config']->set('oauth-helper.mcp.user_model', User::class);
        $app['config']->set('auth.guards.api', ['driver' => 'keycloak']);
        $app['config']->set('auth.guards.mcp', ['driver' => 'keycloak-mcp']);
    }

    protected function defineDatabaseMigrations(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
        (require __DIR__.'/../database/migrations/create_oidc_sessions_table.php.stub')->up();
    }

    protected function tearDown(): void {
        McpGuard::resolveUsersUsing(null);
        parent::tearDown();
    }
}
