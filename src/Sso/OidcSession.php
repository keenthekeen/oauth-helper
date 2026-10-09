<?php

namespace Keenthekeen\OAuthHelper\Sso;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A local session and the Keycloak SSO session (sid) it signed in with, for back-channel logout.
 * A sid may map to several local sessions, one per domain the user signed in on.
 *
 * @property string $session_id
 * @property string $sid
 * @property string $sub
 * @property int|string $user_id
 */
class OidcSession extends Model {
    protected $table = 'oidc_sessions';

    protected $primaryKey = 'session_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'session_id',
        'sid',
        'sub',
        'user_id',
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(config('oauth-helper.sso.user_model'));
    }
}
