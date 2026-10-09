<?php

namespace Keenthekeen\OAuthHelper\Sso;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Lottery;
use Laravel\Socialite\Facades\Socialite;

/**
 * Tracks which local sessions belong to which Keycloak SSO session, so that a back-channel logout
 * (OpenID Connect Back-Channel Logout 1.0) can destroy them, and keeps the id_token for RP-initiated logout.
 */
class OidcSessionRegistry {
    public function __construct(
        protected int $sessionMax,
        protected string $idTokenSessionKey = 'oidc_id_token',
    ) {}

    /**
     * Call right after Auth::login(), which gives the session a new ID.
     *
     * @param  array<string, mixed>  $claims  Claims of the verified id_token
     * @param  string|null  $idToken  The raw id_token, kept as id_token_hint for logout
     */
    public function remember(Request $request, Authenticatable $user, array $claims, ?string $idToken = null): void {
        if ($idToken !== null) {
            $request->session()->put($this->idTokenSessionKey, $idToken);
        }

        if (! empty($claims['sid']) and ! empty($claims['sub'])) {
            // Keyed by the local session: the same sid comes back when the user signs in on another of
            // the app's domains, and each of those sessions must be destroyed on back-channel logout.
            OidcSession::updateOrCreate(['session_id' => $request->session()->getId()], [
                'sid' => $claims['sid'],
                'sub' => $claims['sub'],
                'user_id' => $user->getAuthIdentifier(),
            ]);
        }

        // Prune by age rather than by looking up the sessions table, which only exists with the database
        // session driver: an SSO session cannot outlive the realm's SSO Session Max.
        Lottery::odds(...config('session.lottery', [2, 100]))
            ->winner(fn () => $this->prune())
            ->choose();
    }

    /**
     * Call on a local logout, before the session is invalidated. Returns the id_token for the logout URL.
     */
    public function forget(Request $request): ?string {
        if (! $request->hasSession()) {
            return null;
        }
        OidcSession::whereKey($request->session()->getId())->delete();

        return $request->session()->pull($this->idTokenSessionKey);
    }

    /**
     * URL of Keycloak's end-session endpoint (RP-initiated logout). With the id_token from forget() as
     * id_token_hint, Keycloak logs the user out without asking for confirmation.
     */
    public function logoutUrl(?string $idToken, string $postLogoutRedirectUri): string {
        return Socialite::driver(config('oauth-helper.sso.driver'))
            ->logout($idToken, $postLogoutRedirectUri)
            ->getTargetUrl();
    }

    /**
     * Destroy the local sessions of the SSO session a verified logout_token names: with sid, that SSO
     * session only; with only sub, every session of the user.
     *
     * @param  array<string, mixed>  $claims  Claims of the verified logout_token
     * @return int Number of local sessions destroyed
     */
    public function destroy(array $claims): int {
        $query = ! empty($claims['sid']) ? OidcSession::where('sid', $claims['sid']) : OidcSession::where('sub', $claims['sub'] ?? '');
        $oidcSessions = $query->get();

        $handler = Session::getHandler();
        foreach ($oidcSessions as $oidcSession) {
            $handler->destroy($oidcSession->session_id);
        }
        OidcSession::whereIn('session_id', $oidcSessions->pluck('session_id'))->delete();

        return $oidcSessions->count();
    }

    /**
     * Delete mappings older than SSO Session Max, which Keycloak can no longer log out.
     */
    public function prune(): int {
        return OidcSession::where('created_at', '<', now()->subSeconds($this->sessionMax))->delete();
    }
}
