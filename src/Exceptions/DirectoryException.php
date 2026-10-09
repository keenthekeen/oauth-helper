<?php

namespace Keenthekeen\OAuthHelper\Exceptions;

/**
 * The user directory refused or failed a request.
 */
class DirectoryException extends OAuthHelperException {
    public function __construct(string $message, public readonly ?int $status = null) {
        parent::__construct($message);
    }
}
