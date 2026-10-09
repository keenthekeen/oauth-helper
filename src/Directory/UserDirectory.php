<?php

namespace Keenthekeen\OAuthHelper\Directory;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Keenthekeen\OAuthHelper\Exceptions\DirectoryException;

/**
 * Looks up users of the authorization server.
 *
 * Results hold personal data: authorize the caller (e.g. with a Gate), rate-limit and log searches,
 * and pass on only the fields the caller needs.
 */
interface UserDirectory {
    /**
     * Search username, email, first and last name, prefix-matching by default. Wildcards and quotes in the query are ignored.
     *
     * @return Collection<int, DirectoryUser>
     *
     * @throws InvalidArgumentException When the query is shorter than the configured minimum
     * @throws DirectoryException
     */
    public function search(string $query, SearchMode $mode = SearchMode::Prefix, int $first = 0, ?int $max = null): Collection;

    /**
     * Number of users the search would find.
     *
     * @throws InvalidArgumentException|DirectoryException
     */
    public function count(string $query, SearchMode $mode = SearchMode::Prefix): int;

    /**
     * @throws DirectoryException
     */
    public function find(string $id): ?DirectoryUser;

    /**
     * @throws DirectoryException
     */
    public function findByUsername(string $username): ?DirectoryUser;

    /**
     * @throws DirectoryException
     */
    public function findByEmail(string $email): ?DirectoryUser;

    /**
     * Users whose attribute equals the value exactly, e.g. findByAttribute('studentId', '6430012345').
     *
     * @return Collection<int, DirectoryUser>
     *
     * @throws InvalidArgumentException When the name or value cannot be expressed in an attribute query
     * @throws DirectoryException
     */
    public function findByAttribute(string $name, string $value, int $max = 10): Collection;
}
