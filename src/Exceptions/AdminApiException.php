<?php

namespace Keenthekeen\OAuthHelper\Exceptions;

/**
 * The Keycloak Admin REST API refused or failed a request.
 */
class AdminApiException extends OAuthHelperException {
    public function __construct(string $message, public readonly ?int $status = null) {
        parent::__construct($message);
    }
}
