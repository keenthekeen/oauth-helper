<?php

namespace Keenthekeen\OAuthHelper\Directory;

/**
 * How the search string is matched against username, email, first and last name.
 */
enum SearchMode {
    /** The field starts with the query */
    case Prefix;

    /** The field contains the query; slower on large directories and LDAP-federated users */
    case Infix;

    /** The field equals the query */
    case Exact;

    public function wrap(string $query): string {
        return match ($this) {
            self::Prefix => $query.'*',
            self::Infix => '*'.$query.'*',
            self::Exact => '"'.$query.'"',
        };
    }
}
