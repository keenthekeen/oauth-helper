<?php

namespace Keenthekeen\OAuthHelper\Token;

class Url {
    /**
     * Is the URL the base URL or under its path (same scheme, host and port, no query or fragment)?
     */
    public static function isWithin(string $url, string $base): bool {
        $url = parse_url($url);
        $base = parse_url($base);
        if (empty($url['scheme']) or empty($url['host']) or empty($base['scheme']) or empty($base['host'])
            or isset($url['query']) or isset($url['fragment'])) {
            return false;
        }

        $basePath = rtrim($base['path'] ?? '', '/');
        $path = rtrim($url['path'] ?? '', '/');

        return strtolower($url['scheme']) === strtolower($base['scheme'])
            and strtolower($url['host']) === strtolower($base['host'])
            and ($url['port'] ?? null) === ($base['port'] ?? null)
            and ($path === $basePath or str_starts_with($path, $basePath.'/'));
    }
}
