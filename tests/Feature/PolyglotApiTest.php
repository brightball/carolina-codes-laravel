<?php

namespace Tests\Feature;

use App\Catalog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class PolyglotApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Catalog::resetCounts();
        Catalog::$queryFn = null;
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
        $proc = proc_open(
            [$php, $artisan, 'migrate'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );
        $this->assertIsResource($proc);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('must not alter the shared Postgres catalog', $stderr);
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
        $this->assertSame('Laravel', $body['framework']);
        $this->assertSame(0, Catalog::$sqlCount);
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

    private function ensureCatalog(): void
    {
        if (Catalog::$queryFn !== null) {
            return;
        }
        try {
            Catalog::query('SELECT 1 AS ok');
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
