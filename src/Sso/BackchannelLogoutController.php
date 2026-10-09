<?php

namespace Keenthekeen\OAuthHelper\Sso;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Socialite\Facades\Socialite;

/**
 * Back-channel logout endpoint called by Keycloak (OpenID Connect Back-Channel Logout 1.0).
 * Keycloak calls it without cookies or CSRF token: register it with Route::oidcBackchannelLogout(),
 * outside the web middleware group.
 */
class BackchannelLogoutController {
    public function __invoke(Request $request, OidcSessionRegistry $registry): Response|JsonResponse {
        $headers = ['Cache-Control' => 'no-store'];
        $logoutToken = $request->input('logout_token');

        try {
            if (! is_string($logoutToken) or $logoutToken === '') {
                throw new InvalidArgumentException('Logout token: missing.');
            }
            // Verifies signature, iss, aud, time claims and the logout event, and refuses a replayed jti
            $claims = Socialite::driver(config('oauth-helper.sso.driver'))->verifyLogoutToken($logoutToken);
        } catch (InvalidArgumentException $e) {
            Log::warning('Back-channel logout rejected.', ['message' => $e->getMessage(), 'ip_address' => $request->ip()]);

            return response()->json(['error' => 'invalid_request'], 400, $headers);
        }

        $count = $registry->destroy($claims);
        Log::info('Back-channel logout from identity provider.', [
            'sid' => $claims['sid'] ?? null,
            'sub' => $claims['sub'] ?? null,
            'sessions' => $count,
        ]);

        return response()->noContent(200, $headers);
    }
}
