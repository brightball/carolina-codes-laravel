<?php

namespace Tests\Feature;

use App\Catalog;
use Tests\TestCase;

class ServeReadinessTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<resource> */
    private array $sockets = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
        foreach ($this->processes as $process) {
            $this->stopProcess($process);
        }
        $this->processes = [];
        $this->restoreRegistrationEnv();
        Catalog::reset();
        parent::tearDown();
    }

    public function test_register_is_a_noop_when_url_or_token_is_empty_and_posts_once_otherwise(): void
    {
        $dir = $this->tempDir();
        $server = $this->startSink($dir);
        $this->pushRegistrationEnv([
            'CAROLINA_URL' => '',
            'POLYGLOT_REGISTER_TOKEN' => 'dev-token',
            'PUBLIC_BASE_URL' => 'http://public.example/laravel',
            'PORT' => '4022',
        ]);
        $started = microtime(true);
        Catalog::registerWithElixir();
        $this->assertLessThan(0.5, microtime(true) - $started);
        $this->pushRegistrationEnv([
            'CAROLINA_URL' => 'http://127.0.0.1:'.$server['port'],
            'POLYGLOT_REGISTER_TOKEN' => '',
        ]);
        Catalog::registerWithElixir();
        $this->assertSame(0, $this->sinkCount($dir));
        $this->assertSame(0, Catalog::$connectCount);

        Catalog::reset();
        $this->pushRegistrationEnv([
            'CAROLINA_URL' => 'http://127.0.0.1:'.$server['port'],
            'POLYGLOT_REGISTER_TOKEN' => 'dev-token',
            'PUBLIC_BASE_URL' => 'http://public.example/laravel',
        ]);
        Catalog::registerWithElixir();
        Catalog::registerWithElixir();
        $this->assertSame(1, $this->awaitSinkCount($dir, 1));
        $payload = $this->sinkJson($dir);
        $this->assertSame('PHP', $payload['language'] ?? null);
        $this->assertSame('Laravel', $payload['framework'] ?? null);
        $this->assertSame('http://public.example/laravel', $payload['base_url'] ?? null);
        $this->assertSame(0, Catalog::$connectCount);
        $this->assertSame(0, Catalog::$sqlCount);

        Catalog::reset();
        $closed = $this->freePort();
        $this->pushRegistrationEnv([
            'CAROLINA_URL' => 'http://127.0.0.1:'.$closed,
            'POLYGLOT_REGISTER_TOKEN' => 'dev-token',
        ]);
        $failedAt = microtime(true);
        Catalog::registerWithElixir();
        $this->assertLessThan(Catalog::REGISTER_TIMEOUT_SECONDS, microtime(true) - $failedAt);
        $this->pushRegistrationEnv([
            'CAROLINA_URL' => 'http://127.0.0.1:'.$server['port'],
            'POLYGLOT_REGISTER_TOKEN' => 'dev-token',
        ]);
        Catalog::registerWithElixir();
        usleep(300000);
        $this->assertSame(1, $this->sinkCount($dir));
    }

    public function test_health_returns_before_hung_registration_times_out(): void
    {
        $hung = $this->listenHung();
        $port = $this->freePort();
        $logs = $this->tempDir();
        $loaded = php_ini_loaded_file();
        $phprc = is_string($loaded) ? $loaded : '';
        $databaseUrl = 'postgres://sentinel:sentinel@127.0.0.1:5999/sentinel_db';
        $public = 'http://127.0.0.1:'.$port;
        $carolina = 'http://127.0.0.1:'.$hung['port'];
        $proc = $this->startServe($port, [
            'CAROLINA_URL' => $carolina,
            'POLYGLOT_REGISTER_TOKEN' => 'dev-token',
            'PUBLIC_BASE_URL' => $public,
            'DATABASE_URL' => $databaseUrl,
            'PHPRC' => $phprc,
        ], $logs.'/serve-hung.out', $logs.'/serve-hung.err');

        $health = $this->waitForJson($port, '/health', Catalog::REGISTER_TIMEOUT_SECONDS);
        $this->assertLessThan(
            Catalog::REGISTER_TIMEOUT_SECONDS,
            $health['elapsed'],
            "health waited on registration\n".(string) @file_get_contents($logs.'/serve-hung.err')
        );
        $this->assertSame('ok', $health['json']['status'] ?? null);

        $conn = $this->awaitConnection($hung['socket']);
        $this->assertIsResource($conn);
        $request = $this->readHttpHeaders($conn);
        $this->assertStringContainsString('POST /internal/api-endpoints/register', $request);
        $this->assertFalse(stream_get_meta_data($conn)['eof']);
        $extra = @stream_socket_accept($hung['socket'], 0.2);
        $this->assertFalse(is_resource($extra), 'registration should run once per process');
        if (is_resource($extra)) {
            fclose($extra);
        }

        $home = $this->waitForJson($port, '/', 2);
        $this->assertSame('PHP', $home['json']['language'] ?? null);
        $this->assertSame('Laravel', $home['json']['framework'] ?? null);

        $status = proc_get_status($proc);
        $this->assertTrue($status['running']);
        $worker = $this->workerEnviron((int) $status['pid']);
        $this->assertSame($databaseUrl, $worker['DATABASE_URL'] ?? null);
        $this->assertSame($carolina, $worker['CAROLINA_URL'] ?? null);
        $this->assertSame('dev-token', $worker['POLYGLOT_REGISTER_TOKEN'] ?? null);
        $this->assertSame($public, $worker['PUBLIC_BASE_URL'] ?? null);
        $this->assertSame((string) $port, $worker['PORT'] ?? null);
        $this->assertSame($phprc, $worker['PHPRC'] ?? null);
    }

    public function test_empty_registration_env_does_not_call_elixir_and_worker_receives_env(): void
    {
        $hung = $this->listenHung();
        $port = $this->freePort();
        $loaded = php_ini_loaded_file();
        $phprc = is_string($loaded) ? $loaded : '';
        $stdout = $this->tempDir().'/serve-empty.out';
        $stderr = $this->tempDir().'/serve-empty.err';
        $databaseUrl = 'postgres://sentinel:sentinel@127.0.0.1:5999/sentinel_db';
        $public = 'http://127.0.0.1:'.$port;
        $proc = $this->startServe($port, [
            'CAROLINA_URL' => '',
            'POLYGLOT_REGISTER_TOKEN' => '',
            'PUBLIC_BASE_URL' => $public,
            'DATABASE_URL' => $databaseUrl,
            'PHPRC' => $phprc,
            'PORT' => (string) $port,
        ], $stdout, $stderr);

        $health = $this->waitForJson($port, '/health', Catalog::REGISTER_TIMEOUT_SECONDS);
        $this->assertSame('ok', $health['json']['status'] ?? null, (string) @file_get_contents($stderr));
        $home = $this->waitForJson($port, '/', 2);
        $this->assertSame('PHP', $home['json']['language'] ?? null);
        $this->assertSame('Laravel', $home['json']['framework'] ?? null);

        usleep(400000);
        $late = @stream_socket_accept($hung['socket'], 0.1);
        $this->assertFalse(is_resource($late), 'empty CAROLINA_URL and token must not register');
        if (is_resource($late)) {
            fclose($late);
        }

        $status = proc_get_status($proc);
        $this->assertTrue($status['running']);
        $worker = $this->workerEnviron((int) $status['pid']);
        $this->assertSame($databaseUrl, $worker['DATABASE_URL'] ?? null);
        $this->assertSame($public, $worker['PUBLIC_BASE_URL'] ?? null);
        $this->assertSame((string) $port, $worker['PORT'] ?? null);
        $this->assertSame($phprc, $worker['PHPRC'] ?? null);
        $this->assertArrayNotHasKey('CAROLINA_URL', $worker);
        $this->assertArrayNotHasKey('POLYGLOT_REGISTER_TOKEN', $worker);
    }

    public function test_failed_registration_does_not_stop_the_server(): void
    {
        $port = $this->freePort();
        $closed = $this->freePort();
        $stderr = $this->tempDir().'/serve-refused.err';
        $proc = $this->startServe($port, [
            'CAROLINA_URL' => 'http://127.0.0.1:'.$closed,
            'POLYGLOT_REGISTER_TOKEN' => 'dev-token',
            'PUBLIC_BASE_URL' => 'http://127.0.0.1:'.$port,
        ], $this->tempDir().'/serve-refused.out', $stderr);

        $health = $this->waitForJson($port, '/health', Catalog::REGISTER_TIMEOUT_SECONDS);
        $this->assertLessThan(Catalog::REGISTER_TIMEOUT_SECONDS, $health['elapsed'], (string) @file_get_contents($stderr));
        $this->assertSame('ok', $health['json']['status'] ?? null);
        $status = proc_get_status($proc);
        $this->assertTrue($status['running']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return resource
     */
    private function startServe(int $port, array $overrides, string $stdout, string $stderr)
    {
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }
        foreach ($overrides as $key => $value) {
            $env[$key] = $value;
        }
        $env['PORT'] = (string) $port;
        $proc = proc_open(
            [PHP_BINARY, base_path('artisan'), 'carolina:serve', '--host=127.0.0.1', '--port='.(string) $port],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ],
            $pipes,
            base_path(),
            $env
        );
        $this->assertIsResource($proc);
        $this->processes[] = $proc;

        return $proc;
    }

    /**
     * @return array{json: array<string, mixed>, elapsed: float}
     */
    private function waitForJson(int $port, string $path, float $budget): array
    {
        $url = 'http://127.0.0.1:'.$port.$path;
        $context = stream_context_create([
            'http' => [
                'timeout' => 0.5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $start = microtime(true);
        $raw = false;
        while ((microtime(true) - $start) < $budget) {
            $raw = @file_get_contents($url, false, $context);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return ['json' => $decoded, 'elapsed' => microtime(true) - $start];
                }
            }
            usleep(40000);
        }

        $this->fail('no JSON from '.$url.' after '.round(microtime(true) - $start, 3).'s body='.var_export($raw, true));
    }

    /**
     * @return array{socket: resource, port: int}
     */
    private function listenHung(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        stream_set_blocking($socket, false);
        $this->sockets[] = $socket;
        $name = stream_socket_get_name($socket, false);
        $this->assertIsString($name);

        return ['socket' => $socket, 'port' => $this->portOf($name)];
    }

    /**
     * @param  resource  $socket
     * @return resource|false
     */
    private function awaitConnection($socket)
    {
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline) {
            $conn = @stream_socket_accept($socket, 0);
            if (is_resource($conn)) {
                stream_set_blocking($conn, false);
                $this->sockets[] = $conn;

                return $conn;
            }
            usleep(20000);
        }

        return false;
    }

    /**
     * @param  resource  $conn
     */
    private function readHttpHeaders($conn): string
    {
        $buf = '';
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && ! str_contains($buf, "\r\n\r\n")) {
            $chunk = fread($conn, 8192);
            if (is_string($chunk) && $chunk !== '') {
                $buf .= $chunk;
            } else {
                usleep(10000);
            }
        }

        return $buf;
    }

    /**
     * @return array<string, string>
     */
    private function workerEnviron(int $pid): array
    {
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline) {
            $raw = @file_get_contents("/proc/{$pid}/task/{$pid}/children");
            $ids = preg_split('/\s+/', trim((string) $raw)) ?: [];
            foreach ($ids as $id) {
                if ($id === '') {
                    continue;
                }
                $cmd = @file_get_contents('/proc/'.$id.'/cmdline');
                if (! is_string($cmd) || ! str_contains($cmd, '-S')) {
                    continue;
                }
                $env = @file_get_contents('/proc/'.$id.'/environ');
                if (! is_string($env)) {
                    continue;
                }
                $parsed = [];
                foreach (explode("\0", $env) as $item) {
                    if ($item === '' || ! str_contains($item, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $item, 2);
                    $parsed[$key] = $value;
                }

                return $parsed;
            }
            usleep(20000);
        }

        return [];
    }

    /**
     * @return array{proc: resource, port: int}
     */
    private function startSink(string $dir): array
    {
        file_put_contents($dir.'/count', '0');
        file_put_contents($dir.'/body', '');
        $script = $dir.'/sink.php';
        file_put_contents($script, <<<'PHP'
<?php
$sock = stream_socket_server('tcp://127.0.0.1:0');
if ($sock === false) {
    fwrite(STDERR, "bind failed\n");
    exit(1);
}
$name = stream_socket_get_name($sock, false);
fwrite(STDOUT, $name."\n");
fflush(STDOUT);
$countFile = getenv('COUNT_FILE') ?: '';
$bodyFile = getenv('BODY_FILE') ?: '';
$deadline = microtime(true) + 20;
while (microtime(true) < $deadline) {
    $conn = @stream_socket_accept($sock, 1);
    if (! is_resource($conn)) {
        continue;
    }
    stream_set_timeout($conn, 2);
    $buf = '';
    while (! str_contains($buf, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
    }
    $n = (int) @file_get_contents($countFile);
    file_put_contents($countFile, (string) ($n + 1));
    file_put_contents($bodyFile, $buf);
    fwrite($conn, "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($conn);
}
PHP);
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }
        $env['COUNT_FILE'] = $dir.'/count';
        $env['BODY_FILE'] = $dir.'/body';
        $proc = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            $env
        );
        $this->assertIsResource($proc);
        $this->processes[] = $proc;
        $line = fgets($pipes[1]);
        $this->assertIsString($line);

        return ['proc' => $proc, 'port' => $this->portOf(trim($line))];
    }

    private function sinkCount(string $dir): int
    {
        return (int) @file_get_contents($dir.'/count');
    }

    private function awaitSinkCount(string $dir, int $expected): int
    {
        $deadline = microtime(true) + 3;
        $count = 0;
        while (microtime(true) < $deadline) {
            $count = $this->sinkCount($dir);
            if ($count >= $expected) {
                return $count;
            }
            usleep(20000);
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function sinkJson(string $dir): array
    {
        $raw = (string) file_get_contents($dir.'/body');
        $pos = strpos($raw, "\r\n\r\n");
        $this->assertNotFalse($pos);
        $decoded = json_decode(substr($raw, $pos + 4), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function pushRegistrationEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->savedEnv)) {
                $current = getenv($key);
                $this->savedEnv[$key] = $current === false ? false : (string) $current;
            }
            putenv($key.'='.$value);
        }
    }

    private function restoreRegistrationEnv(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key.'='.$value);
            }
        }
        $this->savedEnv = [];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        $this->assertIsString($name);
        fclose($socket);

        return $this->portOf($name);
    }

    private function portOf(string $name): int
    {
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/carolina-serve-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function stopProcess(mixed $proc): void
    {
        if (! is_resource($proc)) {
            return;
        }
        $status = proc_get_status($proc);
        if (is_array($status) && ! empty($status['running']) && ! empty($status['pid']) && function_exists('posix_kill')) {
            posix_kill((int) $status['pid'], SIGTERM);
            $deadline = microtime(true) + 3;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($proc);
                if (empty($status['running'])) {
                    break;
                }
                usleep(30000);
            }
            if (! empty($status['running'])) {
                posix_kill((int) $status['pid'], SIGKILL);
            }
        }
        proc_close($proc);
    }
}
