<?php

namespace Keenthekeen\OAuthHelper\Exceptions;

/**
 * The access token is malformed, expired, wrongly signed, or meant for another issuer or audience.
 */
class InvalidTokenException extends OAuthHelperException {}
