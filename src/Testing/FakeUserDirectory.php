<?php

namespace Keenthekeen\OAuthHelper\Testing;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Keenthekeen\OAuthHelper\Admin\KeycloakUser;
use Keenthekeen\OAuthHelper\Admin\SearchMode;
use Keenthekeen\OAuthHelper\Contracts\UserDirectory;
use PHPUnit\Framework\Assert;

/**
 * In-memory user directory for tests, matching like Keycloak does (case-insensitive).
 */
class FakeUserDirectory implements UserDirectory {
    /** @var Collection<int, KeycloakUser> */
    protected Collection $users;

    /** @var array<int, string> */
    public array $searches = [];

    /**
     * @param  array<int, array<string, mixed>>  $users  UserRepresentations
     * @param  array<int, string>  $attributes
     */
    public function __construct(array $users = [], array $attributes = [], protected int $minQueryLength = 3) {
        $this->users = collect($users)->map(fn (array $user, int $i) => KeycloakUser::fromRepresentation(
            ['id' => $user['id'] ?? 'user-'.$i, 'enabled' => true, ...$user],
            $attributes,
        ));
    }

    public function search(string $query, SearchMode $mode = SearchMode::Prefix, int $first = 0, ?int $max = null): Collection {
        return $this->matching($query, $mode)->slice($first, $max)->values();
    }

    public function count(string $query, SearchMode $mode = SearchMode::Prefix): int {
        return $this->matching($query, $mode)->count();
    }

    public function find(string $id): ?KeycloakUser {
        return $this->users->firstWhere('id', $id);
    }

    public function findByUsername(string $username): ?KeycloakUser {
        return $this->users->first(fn (KeycloakUser $user) => Str::lower($user->username) === Str::lower($username));
    }

    public function findByEmail(string $email): ?KeycloakUser {
        return $this->users->first(fn (KeycloakUser $user) => Str::lower((string) $user->email) === Str::lower($email));
    }

    public function findByAttribute(string $name, string $value, int $max = 10): Collection {
        return $this->users->filter(fn (KeycloakUser $user) => in_array($value, $user->attributes[$name] ?? [], true))
            ->take($max)
            ->values();
    }

    public function assertSearched(string $query): void {
        Assert::assertContains($query, $this->searches, "The user directory was not searched for [$query].");
    }

    /**
     * @return Collection<int, KeycloakUser>
     */
    protected function matching(string $query, SearchMode $mode): Collection {
        $query = trim(str_replace(['*', '"'], '', $query));
        if (mb_strlen($query) < $this->minQueryLength) {
            throw new InvalidArgumentException("Search for at least {$this->minQueryLength} characters.");
        }
        $this->searches[] = $query;
        $query = Str::lower($query);

        return $this->users->filter(function (KeycloakUser $user) use ($query, $mode) {
            foreach ([$user->username, $user->email, $user->firstName, $user->lastName] as $field) {
                $field = Str::lower((string) $field);
                $matches = match ($mode) {
                    SearchMode::Prefix => str_starts_with($field, $query),
                    SearchMode::Infix => str_contains($field, $query),
                    SearchMode::Exact => $field === $query,
                };
                if ($matches) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
