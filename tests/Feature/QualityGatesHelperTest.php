<?php

namespace Tests\Feature;

use Tests\TestCase;

class QualityGatesHelperTest extends TestCase
{
    public function test_helper_pack_unpack_restores_fixture_including_executable_tool(): void
    {
        $root = $this->tempDir();
        $ws = $root.'/ws';
        $tools = $root.'/tools';
        $ext = $root.'/ext';
        $ini = $root.'/ini';
        $this->writeFile($ws.'/artisan', "#!/usr/bin/env php\n<?php echo \"ok\";\n");
        $this->writeFile($ws.'/vendor/autoload.php', "<?php\n");
        $this->writeFile($tools.'/gitleaks', "#!/bin/sh\necho gitleaks\n");
        chmod($tools.'/gitleaks', 0755);
        $this->writeFile($ext.'/zip.so', "fake-zip-extension\n");
        $this->writeFile($ini.'/docker-php-ext-zip.ini', "extension=zip\n");
        $tar = $root.'/prepared-env.tar.gz';
        $lib = $root.'/lib';

        $out = $this->runHelper('pack', [
            'GITHUB_WORKSPACE' => $ws,
            'CI_ENV_TOOLS_DIR' => $tools,
            'CI_ENV_PHP_EXT_DIR' => $ext,
            'CI_ENV_PHP_INI_DIR' => $ini,
            'CI_ENV_PHP_LIB_DIR' => $lib,
            'CI_ENV_TAR' => $tar,
            'GITHUB_ENV' => $root.'/github.env',
        ]);
        $this->assertFileExists($tar);
        $this->assertStringContainsString('packed', $out);

        $ws2 = $root.'/ws2';
        $tools2 = $root.'/tools2';
        $ext2 = $root.'/ext2';
        $ini2 = $root.'/ini2';
        $unpackOut = $this->runHelper('unpack', [
            'GITHUB_WORKSPACE' => $ws2,
            'CI_ENV_TOOLS_DIR' => $tools2,
            'CI_ENV_PHP_EXT_DIR' => $ext2,
            'CI_ENV_PHP_INI_DIR' => $ini2,
            'CI_ENV_PHP_LIB_DIR' => $root.'/lib2',
            'CI_ENV_TAR' => $tar,
            'GITHUB_ENV' => $root.'/github.env',
        ]);
        $this->assertStringContainsString('restored workspace=', $unpackOut);
        $this->assertSame("#!/usr/bin/env php\n<?php echo \"ok\";\n", file_get_contents($ws2.'/artisan'));
        $this->assertSame("<?php\n", file_get_contents($ws2.'/vendor/autoload.php'));
        $this->assertSame("#!/bin/sh\necho gitleaks\n", file_get_contents($tools2.'/gitleaks'));
        $this->assertSame("fake-zip-extension\n", file_get_contents($ext2.'/zip.so'));
        $this->assertSame("extension=zip\n", file_get_contents($ini2.'/docker-php-ext-zip.ini'));
        $this->assertTrue(is_executable($tools2.'/gitleaks'), 'restored gitleaks must stay executable');
    }

    public function test_helper_upload_download_round_trips_tarball_through_fake_artifact_api(): void
    {
        $root = $this->tempDir();
        $payload = random_bytes(64 * 1024 + 17);
        $src = $root.'/prepared-env.tar.gz';
        $dest = $root.'/downloaded.tar.gz';
        file_put_contents($src, $payload);

        $server = $this->startFakeArtifactServer($root.'/store');
        try {
            $env = [
                'ACTIONS_RUNTIME_URL' => $server['origin'].'/api/actions_pipeline/',
                'ACTIONS_RUNTIME_TOKEN' => 'test-token',
                'GITHUB_RUN_ID' => '42',
                'CI_ENV_TAR' => $src,
                'CI_ENV_ARTIFACT_NAME' => 'prepared-env',
                'CI_ARTIFACT_CHUNK_SIZE' => '8192',
            ];
            $uploadOut = $this->runHelper('upload', $env);
            $this->assertStringContainsString('uploaded artifact prepared-env', $uploadOut);

            $downloadOut = $this->runHelper('download', array_merge($env, [
                'CI_ENV_TAR' => $dest,
            ]));
            $this->assertStringContainsString('downloaded', $downloadOut);
            $this->assertFileExists($dest);
            $this->assertSame($payload, file_get_contents($dest));
        } finally {
            $this->stopProcess($server['proc']);
        }
    }

    public function test_helper_prepare_restore_round_trips_fixture_workspace_via_fake_api(): void
    {
        $root = $this->tempDir();
        $ws = $root.'/ws';
        $tools = $root.'/tools';
        $this->writeFile($ws.'/README.md', "carolina-codes-laravel\n");
        $this->writeFile($tools.'/composer', "#!/bin/sh\necho composer\n");
        $this->writeFile($tools.'/gitleaks', "#!/bin/sh\necho gitleaks\n");
        chmod($tools.'/composer', 0755);
        chmod($tools.'/gitleaks', 0755);
        $tar = $root.'/prepared-env.tar.gz';

        $server = $this->startFakeArtifactServer($root.'/store');
        try {
            $baseEnv = [
                'ACTIONS_RUNTIME_URL' => $server['origin'].'/api/actions_pipeline/',
                'ACTIONS_RUNTIME_TOKEN' => 'test-token',
                'GITHUB_RUN_ID' => '42',
                'CI_ENV_ARTIFACT_NAME' => 'prepared-env',
                'CI_ENV_SKIP_INSTALL' => '1',
                'CI_ENV_PHP_EXT_DIR' => $root.'/ext',
                'CI_ENV_PHP_INI_DIR' => $root.'/ini',
                'CI_ENV_PHP_LIB_DIR' => $root.'/lib',
            ];
            mkdir($root.'/ext', 0755, true);
            mkdir($root.'/ini', 0755, true);
            $prepareOut = $this->runHelper('prepare', array_merge($baseEnv, [
                'GITHUB_WORKSPACE' => $ws,
                'CI_ENV_TOOLS_DIR' => $tools,
                'CI_ENV_TAR' => $tar,
                'GITHUB_ENV' => $root.'/github.env',
            ]));
            $this->assertStringContainsString('uploaded artifact prepared-env', $prepareOut);

            $ws2 = $root.'/restored';
            $tools2 = $root.'/tools-restored';
            $tar2 = $root.'/downloaded.tar.gz';
            $restoreOut = $this->runHelper('restore', array_merge($baseEnv, [
                'GITHUB_WORKSPACE' => $ws2,
                'CI_ENV_TOOLS_DIR' => $tools2,
                'CI_ENV_TAR' => $tar2,
                'CI_ENV_PHP_EXT_DIR' => $root.'/ext2',
                'CI_ENV_PHP_INI_DIR' => $root.'/ini2',
                'CI_ENV_PHP_LIB_DIR' => $root.'/lib2',
                'GITHUB_ENV' => $root.'/github.env',
            ]));
            $this->assertStringContainsString('restoring prepared environment', $restoreOut);
            $this->assertSame("carolina-codes-laravel\n", file_get_contents($ws2.'/README.md'));
            $this->assertSame("#!/bin/sh\necho gitleaks\n", file_get_contents($tools2.'/gitleaks'));
            $this->assertTrue(is_executable($tools2.'/gitleaks'));
            $this->assertTrue(is_executable($tools2.'/composer'));
        } finally {
            $this->stopProcess($server['proc']);
        }
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function runHelper(string $cmd, array $extra, ?string $cwd = null): string
    {
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }
        $env = array_merge($env, $extra);
        $proc = proc_open(
            [PHP_BINARY, base_path('scripts/ci-env.php'), $cmd],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd ?? base_path(),
            $env
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $combined = $stdout.$stderr;
        $this->assertSame(0, $code, "ci-env.php {$cmd} failed:\n{$combined}");

        return $combined;
    }

    /**
     * @return array{proc: resource, origin: string}
     */
    private function startFakeArtifactServer(string $store): array
    {
        if (! is_dir($store)) {
            mkdir($store, 0755, true);
        }
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($sock);
        $name = stream_socket_get_name($sock, false);
        $this->assertIsString($name);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }
        $env['FAKE_ARTIFACT_DIR'] = $store;
        $env['FAKE_ARTIFACT_TOKEN'] = 'test-token';
        $env['FAKE_ARTIFACT_RUN_ID'] = '42';

        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, base_path('tests/fixtures/gitea-artifact-stub.php')],
            [1 => ['file', $store.'/php-s.stdout', 'w'], 2 => ['file', $store.'/php-s.stderr', 'w']],
            $pipes,
            base_path(),
            $env
        );
        $this->assertIsResource($proc);

        $origin = 'http://127.0.0.1:'.$port;
        $deadline = microtime(true) + 5;
        $ready = false;
        while (microtime(true) < $deadline) {
            $health = @file_get_contents($origin.'/health');
            if (is_string($health) && str_contains($health, 'ok')) {
                $ready = true;
                break;
            }
            usleep(50000);
        }
        if (! $ready) {
            $this->stopProcess($proc);
            $this->fail('fake artifact server did not start');
        }

        return ['proc' => $proc, 'origin' => $origin];
    }

    private function stopProcess(mixed $proc): void
    {
        if (! is_resource($proc)) {
            return;
        }
        $status = proc_get_status($proc);
        if (is_array($status) && ! empty($status['pid']) && function_exists('posix_kill')) {
            posix_kill((int) $status['pid'], SIGTERM);
        }
        proc_terminate($proc);
        proc_close($proc);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/ci-env-test-'.bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function writeFile(string $path, string $body): void
    {
        $parent = dirname($path);
        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        file_put_contents($path, $body);
    }
}
