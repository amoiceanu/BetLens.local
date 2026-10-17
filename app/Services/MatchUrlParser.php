<?php

namespace App\Services;

class MatchUrlParser
{
    public function provider(string $url): ?string
    {
        return match (strtolower(parse_url(trim($url), PHP_URL_HOST) ?: '')) {
            'superbet.ro', 'www.superbet.ro' => 'superbet',
            'winbet.ro', 'www.winbet.ro' => 'winbet',
            default => null,
        };
    }

    public function parse(string $url): ?array
    {
        $parts = parse_url(trim(str_replace('\\&', '&', $url)));
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = rawurldecode($parts['path'] ?? '');
        $pattern = match ($host) {
            'superbet.ro', 'www.superbet.ro' => '~^/cote/fotbal/([a-z0-9-]+)-vs-([a-z0-9-]+)-[0-9]+/?$~i',
            'winbet.ro', 'www.winbet.ro' => '~^/in-play/([a-z0-9-]+)-vs-([a-z0-9-]+)-[0-9]+/?$~i',
            default => null,
        };

        if ($pattern === null || !preg_match($pattern, $path, $matches)) {
            return null;
        }

        return [str_replace('-', ' ', $matches[1]), str_replace('-', ' ', $matches[2])];
    }
}
