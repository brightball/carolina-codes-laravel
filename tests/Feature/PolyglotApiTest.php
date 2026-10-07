<?php

namespace Tests\Feature;

use App\Catalog;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

class PolyglotApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Catalog::reset();
    }

    public function test_is_laravel_not_raw_php_sapi(): void
    {
        $composer = file_get_contents(base_path('composer.json'));
        $this->assertStringContainsString('laravel/framework', $composer);
        $this->assertFileExists(base_path('artisan'));
        $this->assertFileDoesNotExist(base_path('server.php'));
        $serve = file_get_contents(app_path('Console/Commands/CarolinaServeCommand.php'));
        $this->assertStringContainsString('carolina:serve', $serve);
        $this->assertStringContainsString("call('serve'", $serve);
        $this->assertSame('PHP', Catalog::LANGUAGE);
        $this->assertSame('Laravel', Catalog::FRAMEWORK);
    }

    public function test_catalog_schema_is_not_managed_by_laravel(): void
    {
        $default = config('database.default');
        $this->assertSame('sqlite', $default);
        $name = (string) config('database.connections.sqlite.database');
        $this->assertSame(':memory:', $name);
        $this->assertStringNotContainsString('carolina_dev', $name);
        $this->assertFalse(file_exists(app_path('Models/Speaker.php')));
        $this->assertFalse(file_exists(app_path('Models/Sponsor.php')));
        $artisan = file_get_contents(base_path('artisan'));
        $this->assertStringContainsString('migrate:fresh', $artisan);
        $this->assertStringContainsString('must not alter the shared Postgres catalog', $artisan);
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $readme = file_get_contents(base_path('README.md'));
        $this->assertStringContainsString('carolina:serve', $dockerfile);
        $this->assertStringContainsString('carolina:serve', $readme);
        $this->assertStringNotContainsString('migrate', $dockerfile);
        $start = explode('```', explode('```bash', $readme, 2)[1], 2)[0];
        $this->assertStringContainsString('carolina:serve', $start);
        $this->assertStringNotContainsString('migrate', $start);
        $this->assertNotSame('pgsql', $default);
    }

    public function test_artisan_refuses_migrate(): void
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        foreach (['migrate', 'migrate:fresh', 'db:wipe'] as $command) {
            $proc = proc_open(
                [$php, $artisan, $command],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path()
            );
            $this->assertIsResource($proc);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $this->assertSame(2, $code, $command);
            $this->assertStringContainsString('must not alter the shared Postgres catalog', (string) $stderr, $command);
        }
    }

    public function test_health_without_sql_or_postgres(): void
    {
        $response = $this->getJson('/health');
        $response->assertOk();
        $body = $response->json();
        $raw = $response->getContent();
        $this->assertStringContainsString('"status"', $raw);
        $this->assertStringContainsString('"ok"', $raw);
        $this->assertSame('ok', $body['status']);
        $this->assertSame(0, Catalog::$sqlCount);
        $this->assertSame(0, Catalog::$connectCount);
    }

    public function test_identity_is_php_laravel_without_sql(): void
    {
        $response = $this->getJson('/');
        $response->assertOk();
        $body = $response->json();
        $this->assertSame('PHP', $body['language']);
        $this->assertSame(PHP_VERSION, $body['language_version']);
        $this->assertSame('Laravel', $body['framework']);
        $this->assertSame(0, Catalog::$sqlCount);
        $this->assertSame(0, Catalog::$connectCount);
    }

    public function test_unknown_speaker_slug_404(): void
    {
        $this->ensureCatalog();
        $response = $this->getJson('/v1/speakers/no-such-slug');
        $response->assertStatus(404);
        $this->assertStringContainsString('not_found', $response->getContent());
    }

    public function test_year_scoped_speakers_include_languages_topics(): void
    {
        $this->ensureCatalog();
        Catalog::resetCounts();
        $response = $this->getJson('/v1/speakers?year=2026');
        $response->assertOk();
        $payload = $response->json();
        $this->assertArrayHasKey('data', $payload);
        $this->assertIsArray($payload['data']);
        $this->assertNotEmpty($payload['data'], 'year-scoped speakers returned rows');
        $row = $payload['data'][0];
        $this->assertArrayHasKey('languages', $row);
        $this->assertArrayHasKey('topics', $row);
        $this->assertIsArray($row['languages']);
        $this->assertIsArray($row['topics']);
        $this->assertGreaterThan(0, Catalog::$sqlCount);
    }

    public function test_year_scoped_sponsors_include_tier(): void
    {
        $this->ensureCatalog();
        $response = $this->getJson('/v1/sponsors?year=2026');
        $response->assertOk();
        $payload = $response->json();
        $this->assertArrayHasKey('data', $payload);
        $this->assertNotEmpty($payload['data']);
        $this->assertArrayHasKey('tier', $payload['data'][0]);
    }

    public function test_laravel_default_connection_is_not_the_catalog(): void
    {
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_collection_routes_return_data_arrays(): void
    {
        $this->installRouteFixture();
        foreach (['/v1/years', '/v1/speakers', '/v1/sponsors'] as $path) {
            $response = $this->getJson($path);
            $response->assertOk();
            $this->assertIsArray($response->json('data'), $path);
        }
    }

    public function test_speaker_routes_return_data_or_not_found(): void
    {
        $this->installRouteFixture();

        $hit = $this->getJson('/v1/speakers/diana-pham');
        $hit->assertOk();
        $this->assertSame('diana-pham', $hit->json('data.slug'));
        $this->assertIsArray($hit->json('data.talks'));

        $miss = $this->getJson('/v1/speakers/no-such-slug');
        $miss->assertStatus(404);
        $miss->assertExactJson(['error' => 'not_found']);

        $yearHit = $this->getJson('/v1/speakers/2026/diana-pham');
        $yearHit->assertOk();
        $this->assertSame('diana-pham', $yearHit->json('data.slug'));
        $this->assertIsArray($yearHit->json('data.languages'));
        $this->assertIsArray($yearHit->json('data.topics'));
        $this->assertNotEmpty($yearHit->json('data.languages'));
        $this->assertNotEmpty($yearHit->json('data.topics'));

        $yearMiss = $this->getJson('/v1/speakers/1999/diana-pham');
        $yearMiss->assertStatus(404);
        $yearMiss->assertExactJson(['error' => 'not_found']);

        $unknownYear = $this->getJson('/v1/speakers/2026/no-such-slug');
        $unknownYear->assertStatus(404);
        $unknownYear->assertExactJson(['error' => 'not_found']);
    }

    public function test_sponsor_routes_return_data_or_not_found(): void
    {
        $this->installRouteFixture();

        $hit = $this->getJson('/v1/sponsors/flywheel');
        $hit->assertOk();
        $this->assertSame('flywheel', $hit->json('data.slug'));

        $miss = $this->getJson('/v1/sponsors/no-such-slug');
        $miss->assertStatus(404);
        $miss->assertExactJson(['error' => 'not_found']);

        $yearList = $this->getJson('/v1/sponsors?year=2026');
        $yearList->assertOk();
        $this->assertIsArray($yearList->json('data'));
        $this->assertNotEmpty($yearList->json('data'));
        $this->assertArrayHasKey('tier', $yearList->json('data.0'));
        $this->assertNotSame('', $yearList->json('data.0.tier'));

        $yearHit = $this->getJson('/v1/sponsors/2026/flywheel');
        $yearHit->assertOk();
        $this->assertSame('flywheel', $yearHit->json('data.slug'));
        $this->assertArrayHasKey('tier', $yearHit->json('data'));

        $yearMiss = $this->getJson('/v1/sponsors/1999/flywheel');
        $yearMiss->assertStatus(404);
        $yearMiss->assertExactJson(['error' => 'not_found']);

        $unknown = $this->getJson('/v1/sponsors/2026/no-such-slug');
        $unknown->assertStatus(404);
        $unknown->assertExactJson(['error' => 'not_found']);
    }

    public function test_year_scoped_speaker_queries_do_not_grow_with_row_count(): void
    {
        $counts = [];
        foreach ([1, 40] as $rows) {
            Catalog::reset();
            Catalog::$queryFn = function (string $sql, array $args) use ($rows): array {
                unset($args);
                if (str_contains($sql, 'FROM v1_speakers')) {
                    $out = [];
                    for ($i = 0; $i < $rows; $i++) {
                        $out[] = [
                            'slug' => 'speaker-'.$i,
                            'first_name' => 'A',
                            'last_name' => 'B'.$i,
                            'name' => 'A B'.$i,
                        ];
                    }

                    return $out;
                }
                if (str_contains($sql, 'FROM v1_talks WHERE year = ?')) {
                    $out = [];
                    for ($i = 0; $i < $rows; $i++) {
                        $out[] = [
                            'slug' => 'talk-'.$i,
                            'title' => 'Talk',
                            'speaker_slug' => 'speaker-'.$i,
                            'year' => 2026,
                            'languages' => '{php}',
                            'topics' => '{api}',
                        ];
                    }

                    return $out;
                }
                if (str_contains($sql, 'speaker_slug IN')) {
                    $out = [];
                    for ($i = 0; $i < $rows; $i++) {
                        $out[] = ['speaker_slug' => 'speaker-'.$i, 'year' => 2026];
                    }

                    return $out;
                }

                return [];
            };
            Catalog::resetCounts();
            $response = $this->getJson('/v1/speakers?year=2026');
            $response->assertOk();
            $data = $response->json('data');
            $this->assertIsArray($data);
            $this->assertCount($rows, $data);
            $this->assertIsArray($data[0]['languages']);
            $this->assertIsArray($data[0]['topics']);
            $counts[$rows] = Catalog::$sqlCount;
        }

        $this->assertSame($counts[1], $counts[40]);
        $this->assertGreaterThan(0, $counts[1]);
        $this->assertLessThan(40, $counts[40]);
    }

    public function test_catalog_reuses_one_pdo_across_queries(): void
    {
        Catalog::reset();
        try {
            $first = Catalog::pdo();
            $second = Catalog::pdo();
            $this->assertSame($first, $second);
            $this->assertSame(1, Catalog::$connectCount);
            $third = Catalog::query('SELECT 1 AS ok');
            $this->assertNotEmpty($third);
            $this->assertSame(1, Catalog::$connectCount);

            return;
        } catch (Throwable) {
            // Catalog postgres is down. The cache branch is still the shipped pdo() path.
        }

        $sqlite = new PDO('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE v1_years (year INTEGER, slug TEXT, name TEXT, status TEXT)');
        $sqlite->exec("INSERT INTO v1_years (year, slug, name, status) VALUES (2026, '2026', 'Year', 'announced')");
        $prop = new ReflectionProperty(Catalog::class, 'pdo');
        $prop->setValue(null, $sqlite);
        Catalog::resetCounts();
        Catalog::$queryFn = null;
        $this->assertSame($sqlite, Catalog::pdo());
        $rows = Catalog::query('SELECT year, slug, name, status FROM v1_years');
        $this->assertSame(2026, (int) $rows[0]['year']);
        $this->assertSame(0, Catalog::$connectCount);
        $this->assertSame(1, Catalog::$sqlCount);
    }

    public function test_serve_passthrough_includes_catalog_env(): void
    {
        foreach (['DATABASE_URL', 'CAROLINA_URL', 'POLYGLOT_REGISTER_TOKEN', 'PUBLIC_BASE_URL', 'PORT', 'PHPRC'] as $key) {
            $this->assertContains($key, ServeCommand::$passthroughVariables);
        }
    }

    public function test_fly_suspends_idle_machines_with_memory_above_256mb(): void
    {
        $fly = (string) file_get_contents(base_path('fly.toml'));
        $this->assertStringContainsString('min_machines_running = 0', $fly);
        $this->assertStringContainsString('auto_start_machines = true', $fly);
        $this->assertStringContainsString('auto_stop_machines = "suspend"', $fly);
        $this->assertStringNotContainsString('auto_stop_machines = "stop"', $fly);
        $this->assertStringNotContainsString('auto_stop_machines = "off"', $fly);
        $this->assertStringContainsString('path = "/health"', $fly);
        $this->assertMatchesRegularExpression('/memory\s*=\s*"(\d+)mb"/', $fly);
        preg_match('/memory\s*=\s*"(\d+)mb"/', $fly, $match);
        $this->assertGreaterThan(256, (int) $match[1]);
    }

    public function test_image_enables_cli_opcache_without_baking_runtime_env(): void
    {
        $docker = (string) file_get_contents(base_path('Dockerfile'));
        $this->assertStringContainsString('carolina:serve', $docker);
        $this->assertStringContainsString('--no-dev', $docker);
        $this->assertStringContainsString('--optimize-autoloader', $docker);
        $this->assertStringContainsString('--classmap-authoritative', $docker);
        $this->assertStringContainsString('opcache.enable=1', $docker);
        $this->assertStringContainsString('opcache.enable_cli=1', $docker);
        $this->assertStringNotContainsString('config:cache', $docker);
        $this->assertStringNotContainsString('migrate', $docker);
        $copy = strpos($docker, 'COPY . .');
        $dump = strpos($docker, 'dump-autoload');
        $this->assertNotFalse($copy);
        $this->assertNotFalse($dump);
        $this->assertGreaterThan($copy, $dump);
        $this->assertFileDoesNotExist(base_path('bootstrap/cache/config.php'));
        $ignore = (string) file_get_contents(base_path('.dockerignore'));
        $this->assertStringContainsString('bootstrap/cache/*.php', $ignore);
        foreach (['DATABASE_URL', 'APP_KEY', 'CAROLINA_URL', 'POLYGLOT_REGISTER_TOKEN', 'PUBLIC_BASE_URL'] as $name) {
            $this->assertDoesNotMatchRegularExpression('/^ENV\s+'.preg_quote($name, '/').'=/m', $docker);
        }
    }

    public function test_committed_docs_state_the_polyglot_contract(): void
    {
        $agents = $this->committed('AGENTS.md');
        $claude = $this->committed('CLAUDE.md');
        $memory = $this->committed('MEMORY.md');
        $decisions = $this->committed('DECISIONS.md');
        $readme = $this->committed('README.md');
        $docker = $this->committed('Dockerfile');
        $composerRaw = $this->committed('composer.json');

        foreach (['boost:install', 'composer require laravel/boost', 'Postgres 16', 'Postgres 18'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $agents, $forbidden);
            $this->assertStringNotContainsString($forbidden, $claude, $forbidden);
        }

        foreach ([
            'ordinary JSON',
            'application/vnd.api+json',
            'v1_*',
            'Ash',
            'base catalog tables',
            '`GET /health`',
            '`GET /`',
            '/v1/years',
            '/v1/speakers',
            '/v1/sponsors',
            '?year=',
            '/{year}/{slug}',
            'CAROLINA_URL',
            'no heartbeat',
            'Registration is not on the health path',
            'database-free',
            'php artisan carolina:serve',
            'PDO',
            ':memory:',
            'migrate',
            'wipe',
            '4022',
            'MEMORY.md',
            'DECISIONS.md',
            'durable decision',
            'raw PHP',
            'SAPI',
            'log and keep serving',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $agents, $phrase);
        }

        $this->assertStringContainsString('AGENTS.md', $claude);
        $this->assertStringContainsString('MEMORY.md', $claude);
        $this->assertStringContainsString('DECISIONS.md', $claude);
        $this->assertStringContainsString('Do not install Laravel Boost', $claude);
        $this->assertStringContainsString('DECISIONS.md', $memory);
        $this->assertFileExists(base_path('MEMORY.md'));
        $this->assertFileExists(base_path('DECISIONS.md'));

        foreach ([
            'PDO',
            'Eloquent',
            ':memory:',
            'migrate',
            'db:wipe',
            'register',
            'health',
            'heartbeat',
            'Psalm',
            'Pint',
            'gitleaks',
            'composer audit',
            '4022',
            'Laravel',
            'SAPI',
            'Pail',
            'Pao',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $decisions, $phrase);
        }

        $decoded = json_decode($composerRaw, true);
        $this->assertIsArray($decoded);
        $require = $decoded['require'] ?? null;
        $this->assertIsArray($require);
        $constraint = $require['laravel/framework'] ?? null;
        $this->assertIsString($constraint);
        $this->assertSame(1, preg_match('/(\d+)/', $constraint, $major));
        $majorVersion = $major[1] ?? null;
        $this->assertIsString($majorVersion);
        $this->assertStringContainsString('Laravel '.$majorVersion, $readme);

        $this->assertSame(1, preg_match('/^FROM\s+(\S+)/m', $docker, $from));
        $image = $from[1] ?? null;
        $this->assertIsString($image);
        $this->assertStringContainsString($image, $readme);

        foreach (['Psalm', 'Pint', 'Pail', 'Pao', 'gitleaks', 'taint', 'agent-oriented'] as $package) {
            $this->assertStringContainsString($package, $readme, $package);
        }
    }

    private function committed(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        if (! is_string($contents)) {
            $this->fail($path.' is not readable');
        }

        return $contents;
    }

    private function installRouteFixture(): void
    {
        Catalog::reset();
        Catalog::$queryFn = function (string $sql, array $args): array {
            return $this->fixtureRows($sql, $args);
        };
    }

    /**
     * @param  list<mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function fixtureRows(string $sql, array $args): array
    {
        if (str_contains($sql, 'FROM v1_years')) {
            return [['year' => 2026, 'slug' => '2026', 'name' => '2026', 'status' => 'announced']];
        }
        if (str_contains($sql, 'FROM v1_year_sponsors WHERE year = ? AND slug = ?')) {
            $year = (int) ($args[0] ?? 0);
            $slug = (string) ($args[1] ?? '');
            if ($year === 2026 && $slug === 'flywheel') {
                return [[
                    'slug' => 'flywheel',
                    'name' => 'Flywheel',
                    'tier' => 'platinum',
                    'year' => 2026,
                    'website' => 'https://flywheel.example',
                ]];
            }

            return [];
        }
        if (str_contains($sql, 'FROM v1_year_sponsors')) {
            if ((int) ($args[0] ?? 0) !== 2026) {
                return [];
            }

            return [[
                'slug' => 'flywheel',
                'name' => 'Flywheel',
                'tier' => 'platinum',
                'year' => 2026,
            ]];
        }
        if (str_contains($sql, 'FROM v1_sponsorships')) {
            $slug = (string) ($args[0] ?? '');
            if ($slug !== 'flywheel') {
                return [];
            }
            if (str_contains($sql, 'DISTINCT year')) {
                return [['year' => 2026]];
            }

            return [['sponsor_slug' => 'flywheel', 'year' => 2026, 'tier' => 'platinum']];
        }
        if (str_contains($sql, 'FROM v1_sponsors WHERE slug = ?')) {
            if ((string) ($args[0] ?? '') !== 'flywheel') {
                return [];
            }

            return [[
                'slug' => 'flywheel',
                'name' => 'Flywheel',
                'website' => 'https://flywheel.example',
            ]];
        }
        if (str_contains($sql, 'FROM v1_sponsors')) {
            return [[
                'slug' => 'flywheel',
                'name' => 'Flywheel',
                'website' => 'https://flywheel.example',
            ]];
        }
        if (str_contains($sql, 'FROM v1_speakers WHERE slug = ?')) {
            if ((string) ($args[0] ?? '') !== 'diana-pham') {
                return [];
            }

            return [[
                'slug' => 'diana-pham',
                'first_name' => 'Diana',
                'last_name' => 'Pham',
                'name' => 'Diana Pham',
            ]];
        }
        if (str_contains($sql, 'FROM v1_speakers')) {
            return [[
                'slug' => 'diana-pham',
                'first_name' => 'Diana',
                'last_name' => 'Pham',
                'name' => 'Diana Pham',
            ]];
        }
        if (str_contains($sql, 'SELECT DISTINCT speaker_slug, year FROM v1_talks')) {
            return [['speaker_slug' => 'diana-pham', 'year' => 2026]];
        }
        if (str_contains($sql, 'SELECT DISTINCT year FROM v1_talks')) {
            if ((string) ($args[0] ?? '') !== 'diana-pham') {
                return [];
            }

            return [['year' => 2026]];
        }
        if (str_contains($sql, 'FROM v1_talks WHERE speaker_slug = ? AND year = ?')) {
            if ((string) ($args[0] ?? '') === 'diana-pham' && (int) ($args[1] ?? 0) === 2026) {
                return [$this->talkRow()];
            }

            return [];
        }
        if (str_contains($sql, 'FROM v1_talks WHERE speaker_slug = ?')) {
            if ((string) ($args[0] ?? '') !== 'diana-pham') {
                return [];
            }

            return [$this->talkRow()];
        }
        if (str_contains($sql, 'FROM v1_talks WHERE year = ?')) {
            if ((int) ($args[0] ?? 0) !== 2026) {
                return [];
            }

            return [$this->talkRow()];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function talkRow(): array
    {
        return [
            'slug' => 'talk',
            'title' => 'Talk',
            'description' => 'A talk',
            'format' => 'talk',
            'youtube_id' => '',
            'year' => 2026,
            'speaker_slug' => 'diana-pham',
            'languages' => '{php}',
            'topics' => '{development}',
        ];
    }

    private function ensureCatalog(): void
    {
        if (Catalog::$queryFn !== null) {
            return;
        }
        try {
            Catalog::query('SELECT 1 AS ok FROM v1_speakers LIMIT 1');
        } catch (Throwable $e) {
            fwrite(STDERR, "postgres unavailable, using query hook: {$e->getMessage()}\n");
            Catalog::$queryFn = function (string $sql, array $args): array {
                if (str_contains($sql, 'FROM v1_speakers WHERE slug =')) {
                    return [];
                }
                if (str_contains($sql, 'FROM v1_speakers')) {
                    return [['slug' => 'diana-pham', 'first_name' => 'Diana', 'last_name' => 'Pham', 'name' => 'Diana Pham']];
                }
                if (str_contains($sql, 'FROM v1_talks')) {
                    return [[
                        'slug' => 'talk',
                        'title' => 'Talk',
                        'speaker_slug' => 'diana-pham',
                        'year' => 2026,
                        'languages' => '{php}',
                        'topics' => '{development}',
                    ]];
                }
                if (str_contains($sql, 'FROM v1_year_sponsors')) {
                    return [['slug' => 'flywheel', 'name' => 'Flywheel', 'tier' => 'platinum', 'year' => 2026]];
                }
                if (str_contains($sql, 'FROM v1_sponsors WHERE slug')) {
                    return [];
                }

                return [];
            };
        }
    }
}
