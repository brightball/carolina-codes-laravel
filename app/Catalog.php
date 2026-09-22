<?php

namespace App;

use PDO;

/**
 * Read-only SQL against PostgreSQL v1_* views. Not Eloquent, not Laravel migrations.
 */
class Catalog
{
    public const LANGUAGE = 'PHP';

    public const FRAMEWORK = 'Laravel';

    public const API_VERSION = '0.2.0';

    public const CREATED_YEAR = 2026;

    public const SCHEMA_VERSION = 1;

    public const ENDPOINTS = [
        ['method' => 'GET', 'path' => '/', 'query' => []],
        ['method' => 'GET', 'path' => '/health', 'query' => []],
        ['method' => 'GET', 'path' => '/v1/years', 'query' => []],
        ['method' => 'GET', 'path' => '/v1/speakers', 'query' => ['year']],
        ['method' => 'GET', 'path' => '/v1/speakers/:slug', 'query' => []],
        ['method' => 'GET', 'path' => '/v1/speakers/:year/:slug', 'query' => []],
        ['method' => 'GET', 'path' => '/v1/sponsors', 'query' => ['year']],
        ['method' => 'GET', 'path' => '/v1/sponsors/:slug', 'query' => []],
        ['method' => 'GET', 'path' => '/v1/sponsors/:year/:slug', 'query' => []],
    ];

    public const SPEAKER_COLS = 'slug, first_name, last_name, name, tagline, bio, company, location, photo_path, twitter_url, linkedin_url, website_url, github_url, featured';

    public const YEAR_SPONSOR_COLS = 'slug, name, website, logo_path, description, blurb, tier, featured, year, twitter_url, linkedin_url, youtube_url, instagram_url, facebook_url';

    public const SPONSOR_COLS = 'slug, name, website, logo_path, description, twitter_url, linkedin_url, youtube_url, instagram_url, facebook_url';

    public const TALK_COLS = 'slug, title, description, format, youtube_id, year, speaker_slug, languages, topics';

    public const REGISTER_TIMEOUT_SECONDS = 5;

    public static int $sqlCount = 0;

    public static int $connectCount = 0;

    /** @var null|callable(string, array<int, mixed>): list<array<string, mixed>> */
    public static $queryFn = null;

    private static ?PDO $pdo = null;

    private static bool $registrationStarted = false;

    public static function resetCounts(): void
    {
        self::$sqlCount = 0;
        self::$connectCount = 0;
    }

    public static function reset(): void
    {
        self::resetCounts();
        self::$queryFn = null;
        self::$pdo = null;
        self::$registrationStarted = false;
    }

    public static function identity(): array
    {
        return [
            'language' => self::LANGUAGE,
            'language_version' => PHP_VERSION,
            'api_version' => self::API_VERSION,
            'framework' => self::FRAMEWORK,
            'created_year' => self::CREATED_YEAR,
            'schema_version' => self::SCHEMA_VERSION,
            'endpoints' => self::ENDPOINTS,
        ];
    }

    public static function pdoDsn(): array
    {
        $raw = getenv('DATABASE_URL') ?: 'postgres://postgres:postgres@127.0.0.1:5432/carolina_dev';
        if (! str_contains($raw, 'sslmode=')) {
            $raw .= (str_contains($raw, '?') ? '&' : '?').'sslmode=disable';
        }
        $url = parse_url(str_replace('postgres://', 'postgresql://', $raw));
        $host = $url['host'] ?? '127.0.0.1';
        $port = $url['port'] ?? 5432;
        $db = ltrim($url['path'] ?? '/carolina_dev', '/');
        $db = explode('?', $db)[0];
        $user = isset($url['user']) ? urldecode($url['user']) : 'postgres';
        $pass = isset($url['pass']) ? urldecode($url['pass']) : 'postgres';
        $query = [];
        if (! empty($url['query'])) {
            parse_str($url['query'], $query);
        }
        $ssl = $query['sslmode'] ?? 'disable';

        return ["pgsql:host={$host};port={$port};dbname={$db};sslmode={$ssl}", $user, $pass];
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        self::$connectCount++;
        [$dsn, $user, $pass] = self::pdoDsn();
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }

    public static function query(string $sql, array $args = []): array
    {
        self::$sqlCount++;
        if (self::$queryFn !== null) {
            return (self::$queryFn)($sql, $args);
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchAll();
    }

    public static function queryOne(string $sql, array $args = []): ?array
    {
        $rows = self::query($sql, $args);

        return $rows[0] ?? null;
    }

    public static function pgTextArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), fn ($v) => $v !== ''));
        }
        $stripped = trim((string) $value);
        if ($stripped === '{}' || $stripped === '') {
            return [];
        }
        if ($stripped[0] === '{' && str_ends_with($stripped, '}')) {
            $stripped = substr($stripped, 1, -1);
        }
        $parts = array_map(fn ($p) => trim($p, " \t\"'"), explode(',', $stripped));

        return array_values(array_filter($parts, fn ($v) => $v !== ''));
    }

    public static function clean(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = [];
        foreach ($row as $k => $v) {
            if ($k === 'languages' || $k === 'topics') {
                $out[$k] = self::pgTextArray($v);
            } elseif ($k === 'year' && $v !== null && $v !== '') {
                $out[$k] = (int) $v;
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public static function uniqTags(array $talks, string $key): array
    {
        $seen = [];
        $out = [];
        foreach ($talks as $talk) {
            foreach (self::pgTextArray($talk[$key] ?? []) as $val) {
                if (! isset($seen[$val])) {
                    $seen[$val] = true;
                    $out[] = $val;
                }
            }
        }

        return $out;
    }

    public static function talksFor(string $slug, ?int $year = null): array
    {
        if ($year === null) {
            $rows = self::query('SELECT '.self::TALK_COLS.' FROM v1_talks WHERE speaker_slug = ? ORDER BY year DESC', [$slug]);
        } else {
            $rows = self::query(
                'SELECT '.self::TALK_COLS.' FROM v1_talks WHERE speaker_slug = ? AND year = ? ORDER BY year DESC',
                [$slug, $year]
            );
        }

        return array_map([self::class, 'clean'], $rows);
    }

    public static function talkYears(string $slug): array
    {
        $rows = self::query('SELECT DISTINCT year FROM v1_talks WHERE speaker_slug = ? ORDER BY year DESC', [$slug]);

        return array_map(fn ($r) => (int) $r['year'], $rows);
    }

    public static function sponsorYears(string $slug): array
    {
        $rows = self::query('SELECT DISTINCT year FROM v1_sponsorships WHERE sponsor_slug = ? ORDER BY year DESC', [$slug]);

        return array_map(fn ($r) => (int) $r['year'], $rows);
    }

    public static function listSpeakers(?int $year = null): array
    {
        if ($year === null) {
            return array_map([self::class, 'clean'], self::query('SELECT '.self::SPEAKER_COLS.' FROM v1_speakers ORDER BY last_name, first_name'));
        }
        $rows = self::query(
            'SELECT '.self::SPEAKER_COLS.' FROM v1_speakers WHERE slug IN (SELECT speaker_slug FROM v1_talks WHERE year = ?) ORDER BY last_name, first_name',
            [$year]
        );

        return self::attachYearTags(array_map([self::class, 'clean'], $rows), $year);
    }

    public static function attachYearTags(array $speakers, int $year): array
    {
        if (! $speakers) {
            return $speakers;
        }
        $slugs = array_column($speakers, 'slug');
        $talksBy = self::loadTalksForYear($year);
        $yearsBy = self::loadYearsForSlugs($slugs);
        foreach ($speakers as &$sp) {
            $slug = $sp['slug'];
            $talks = $talksBy[$slug] ?? [];
            $years = $yearsBy[$slug] ?? [];
            $sp['year'] = $year;
            $sp['talks'] = $talks;
            $sp['languages'] = self::uniqTags($talks, 'languages');
            $sp['topics'] = self::uniqTags($talks, 'topics');
            $sp['years'] = $years;
        }
        unset($sp);

        return $speakers;
    }

    public static function loadTalksForYear(int $year): array
    {
        $rows = self::query('SELECT '.self::TALK_COLS.' FROM v1_talks WHERE year = ? ORDER BY speaker_slug, year DESC', [$year]);
        $out = [];
        foreach ($rows as $row) {
            $talk = self::clean($row);
            $slug = $talk['speaker_slug'] ?? '';
            $out[$slug][] = $talk;
        }

        return $out;
    }

    public static function loadYearsForSlugs(array $slugs): array
    {
        if (! $slugs) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $rows = self::query(
            "SELECT DISTINCT speaker_slug, year FROM v1_talks WHERE speaker_slug IN ({$placeholders}) ORDER BY speaker_slug, year DESC",
            array_values($slugs)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['speaker_slug']][] = (int) $row['year'];
        }

        return $out;
    }

    /**
     * One-shot register. Does not open PDO. A missing URL or token is a no-op.
     * A failed POST is logged and swallowed so the caller can keep serving.
     */
    public static function registerWithElixir(): void
    {
        $url = getenv('CAROLINA_URL') ?: '';
        $token = getenv('POLYGLOT_REGISTER_TOKEN') ?: '';
        if ($url === '' || $token === '') {
            return;
        }
        if (self::$registrationStarted) {
            return;
        }
        self::$registrationStarted = true;

        $port = getenv('PORT') ?: '4022';
        $base = getenv('PUBLIC_BASE_URL') ?: "http://127.0.0.1:{$port}";
        $body = json_encode(self::identity() + ['base_url' => $base]);
        $timeout = self::REGISTER_TIMEOUT_SECONDS;
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $timeout);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
                'content' => $body === false ? '{}' : $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $endpoint = rtrim($url, '/').'/internal/api-endpoints/register';
        $resp = @file_get_contents($endpoint, false, $ctx);
        if (is_string($previousTimeout)) {
            ini_set('default_socket_timeout', $previousTimeout);
        }
        $code = 0;
        if (isset($http_response_header[0]) && is_string($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        fwrite(STDERR, $resp !== false ? "registered with elixir: {$code}\n" : "register: failed\n");
    }
}
