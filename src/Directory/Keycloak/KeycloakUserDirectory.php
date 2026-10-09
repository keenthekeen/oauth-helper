<?php

namespace Keenthekeen\OAuthHelper\Directory\Keycloak;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Keenthekeen\OAuthHelper\Directory\DirectoryUser;
use Keenthekeen\OAuthHelper\Directory\SearchMode;
use Keenthekeen\OAuthHelper\Directory\UserDirectory;

/**
 * User directory backed by the Keycloak Admin REST API (GET /admin/realms/{realm}/users).
 * The directory client needs the realm-management "view-users" role, and nothing more.
 */
class KeycloakUserDirectory implements UserDirectory {
    /**
     * @param  array<int, string>  $attributes  Attributes kept on DirectoryUser
     */
    public function __construct(
        protected KeycloakAdminClient $client,
        protected Cache $cache,
        protected int $maxResults = 50,
        protected int $minQueryLength = 3,
        protected int $cacheTtl = 60,
        protected array $attributes = [],
    ) {}

    public function search(string $query, SearchMode $mode = SearchMode::Prefix, int $first = 0, ?int $max = null): Collection {
        $params = [
            'search' => $mode->wrap($this->sanitize($query)),
            'first' => max(0, $first),
            'max' => min($max ?? $this->maxResults, $this->maxResults),
            'briefRepresentation' => 'false',
        ];

        return $this->users($params);
    }

    public function count(string $query, SearchMode $mode = SearchMode::Prefix): int {
        $params = ['search' => $mode->wrap($this->sanitize($query))];

        return (int) $this->remember('count', $params, fn () => $this->client->get('users/count', $params));
    }

    public function find(string $id): ?DirectoryUser {
        if (! preg_match('/^[A-Za-z0-9-]+$/', $id)) {
            return null;
        }
        $representation = $this->remember('find', [$id], fn () => $this->client->get('users/'.$id));

        return is_array($representation) ? DirectoryUser::fromRepresentation($representation, $this->attributes) : null;
    }

    public function findByUsername(string $username): ?DirectoryUser {
        return $this->users(['username' => $username, 'exact' => 'true', 'max' => 1])->first();
    }

    public function findByEmail(string $email): ?DirectoryUser {
        return $this->users(['email' => $email, 'exact' => 'true', 'max' => 1])->first();
    }

    public function findByAttribute(string $name, string $value, int $max = 10): Collection {
        // The q parameter is "name:value name2:value2", so neither may contain spaces or colons
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name) or $value === '' or preg_match('/[\s:]/', $value)) {
            throw new InvalidArgumentException('The attribute name or value cannot be searched.');
        }

        return $this->users([
            'q' => $name.':'.$value,
            'exact' => 'true',
            'max' => min($max, $this->maxResults),
            'briefRepresentation' => 'false',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, DirectoryUser>
     */
    protected function users(array $params): Collection {
        $representations = $this->remember('users', $params, fn () => $this->client->get('users', $params));

        return collect(is_array($representations) ? $representations : [])
            ->filter(fn ($representation) => is_array($representation) and isset($representation['id']))
            ->map(fn (array $representation) => DirectoryUser::fromRepresentation($representation, $this->attributes))
            ->values();
    }

    /**
     * Strip Keycloak's search syntax (wildcards, quotes) so callers choose matching with SearchMode only,
     * and refuse short queries that would list a large part of the directory.
     */
    protected function sanitize(string $query): string {
        $query = trim(preg_replace('/\s+/u', ' ', str_replace(['*', '"', '%'], ' ', $query)));
        if (mb_strlen($query) < $this->minQueryLength) {
            throw new InvalidArgumentException("Search for at least {$this->minQueryLength} characters.");
        }

        return mb_substr($query, 0, 100);
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    protected function remember(string $type, array $params, Closure $callback): mixed {
        if ($this->cacheTtl <= 0) {
            return $callback();
        }

        $key = 'oauth-helper:directory:'.$type.':'.hash('xxh128', serialize($params));

        return $this->cache->remember($key, $this->cacheTtl, $callback);
    }
}
