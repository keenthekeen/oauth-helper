<?php

namespace Keenthekeen\OAuthHelper\Contracts;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Keenthekeen\OAuthHelper\Admin\KeycloakUser;
use Keenthekeen\OAuthHelper\Admin\SearchMode;
use Keenthekeen\OAuthHelper\Exceptions\AdminApiException;

/**
 * Looks up users of the Keycloak realm.
 *
 * Results hold personal data: authorize the caller (e.g. with a Gate), rate-limit and log searches,
 * and pass on only the fields the caller needs.
 */
interface UserDirectory {
    /**
     * Search username, email, first and last name. Wildcards and quotes in the query are ignored.
     *
     * @return Collection<int, KeycloakUser>
     *
     * @throws InvalidArgumentException When the query is shorter than the configured minimum
     * @throws AdminApiException
     */
    public function search(string $query, SearchMode $mode = SearchMode::Prefix, int $first = 0, ?int $max = null): Collection;

    /**
     * Number of users the search would find.
     *
     * @throws InvalidArgumentException|AdminApiException
     */
    public function count(string $query, SearchMode $mode = SearchMode::Prefix): int;

    /**
     * @throws AdminApiException
     */
    public function find(string $id): ?KeycloakUser;

    /**
     * @throws AdminApiException
     */
    public function findByUsername(string $username): ?KeycloakUser;

    /**
     * @throws AdminApiException
     */
    public function findByEmail(string $email): ?KeycloakUser;

    /**
     * Users whose attribute equals the value exactly, e.g. findByAttribute('studentId', '6430012345').
     *
     * @return Collection<int, KeycloakUser>
     *
     * @throws InvalidArgumentException When the name or value cannot be expressed in a Keycloak attribute query
     * @throws AdminApiException
     */
    public function findByAttribute(string $name, string $value, int $max = 10): Collection;
}
