<?php

namespace Tests\Feature;

use Tests\TestCase;

class QualityGatesTest extends TestCase
{
    public function test_pre_commit_runs_five_named_checks(): void
    {
        $path = base_path('.pre-commit-config.yaml');
        $this->assertFileExists($path);
        $config = file_get_contents($path);
        $this->assertNotFalse($config);

        $hooks = $this->preCommitHooks($config);
        $this->assertSame(
            ['tests', 'sast', 'audit', 'gitleaks', 'pint'],
            array_keys($hooks),
            'pre-commit must list the five checks as distinct local hooks'
        );

        $this->assertSame('php artisan test', $hooks['tests']);
        $this->assertSame('vendor/bin/psalm --taint-analysis --no-cache --memory-limit=1G', $hooks['sast']);
        $this->assertSame('composer audit --locked', $hooks['audit']);
        $this->assertSame('gitleaks detect --source .', $hooks['gitleaks']);
        $this->assertSame('vendor/bin/pint --test', $hooks['pint']);
        $this->assertStringContainsString('--taint-analysis', $hooks['sast']);
        $this->assertStringNotContainsString('make check', $config);
    }

    public function test_gitea_workflow_is_prepare_then_parallel_checks(): void
    {
        $path = base_path('.gitea/workflows/precommit.yml');
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist(base_path('.github/workflows/precommit.yml'));
        $this->assertFileExists(base_path('scripts/ci-env.php'));
        $yaml = file_get_contents($path);
        $this->assertNotFalse($yaml);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s+- uses:\s*actions\/checkout\b/m',
            $yaml,
            'Gitea jobs must clone GITHUB_SHA over HTTPS; do not use the checkout action'
        );
        $this->assertStringNotContainsString('actions/upload-artifact', $yaml);
        $this->assertStringNotContainsString('actions/download-artifact', $yaml);
        $this->assertStringContainsString('php:8.5-cli', $yaml);
        $this->assertStringContainsString('ci-env.php restore', $yaml);
        $this->assertStringContainsString('ci-env.php prepare', $yaml);

        $jobs = $this->giteaJobs($yaml);
        $checks = ['tests', 'sast', 'audit', 'gitleaks', 'pint'];
        foreach ($checks as $name) {
            $this->assertArrayHasKey($name, $jobs, "Gitea workflow must have a {$name} job");
        }
        $prepareName = $this->firstStageJobName($jobs, $checks);
        $this->assertNotSame('', $prepareName, 'workflow must have a distinct first-stage job');
        $this->assertArrayHasKey($prepareName, $jobs);

        $helper = (string) file_get_contents(base_path('scripts/ci-env.php'));
        $prepareSrc = $jobs[$prepareName]."\n".$helper;
        $this->assertStringContainsString('git clone', $prepareSrc);
        $this->assertStringContainsString('GITHUB_SHA', $prepareSrc);
        $this->assertStringContainsString('x-access-token', $prepareSrc);
        $this->assertStringContainsString('docker-php-ext-install', $prepareSrc);
        $this->assertStringContainsString('composer install --prefer-dist --no-interaction --no-progress', $prepareSrc);
        $this->assertStringContainsString('gitleaks_8.30.1', $prepareSrc);
        $this->assertStringContainsString('cmd_upload', $helper);
        $this->assertStringContainsString('/api/actions_pipeline', $helper);
        $this->assertDoesNotMatchRegularExpression('/^\s+needs:/m', $jobs[$prepareName]);
        $this->assertStringContainsString('php:8.5-cli', $jobs[$prepareName]);

        $this->assertStringContainsString('php artisan test', $jobs['tests']);
        $this->assertStringContainsString('vendor/bin/psalm --taint-analysis --no-cache --memory-limit=1G', $jobs['sast']);
        $this->assertStringContainsString('composer audit --locked', $jobs['audit']);
        $this->assertStringContainsString('gitleaks detect --source .', $jobs['gitleaks']);
        $this->assertStringContainsString('vendor/bin/pint --test', $jobs['pint']);

        $forbidden = [
            'git clone',
            'apt-get',
            'docker-php-ext-install',
            'composer install',
            'gitleaks/gitleaks/releases',
            'getcomposer.org/installer',
        ];
        foreach ($checks as $name) {
            $body = $jobs[$name];
            $this->assertMatchesRegularExpression(
                '/^\s+needs:\s+'.preg_quote($prepareName, '/').'\s*$/m',
                $body,
                "job {$name} must need only the first-stage job {$prepareName}"
            );
            foreach ($checks as $other) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^\s+needs:\s+'.preg_quote($other, '/').'\s*$/m',
                    $body,
                    "job {$name} must not needs: {$other}"
                );
            }
            $this->assertTrue(
                str_contains($body, '*restore-prepared-env') || str_contains($body, 'ci-env.php restore'),
                "job {$name} must restore the prepared environment"
            );
            $this->assertStringContainsString('php:8.5-cli', $body);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $body,
                    "job {$name} must not {$needle}; that belongs in prepare"
                );
            }
        }

        $needles = [
            'php artisan test',
            'psalm --taint-analysis',
            'composer audit --locked',
            'gitleaks detect --source .',
            'pint --test',
        ];
        foreach ($jobs as $name => $body) {
            $hits = 0;
            foreach ($needles as $needle) {
                if (str_contains($body, $needle)) {
                    $hits++;
                }
            }
            $this->assertLessThan(
                5,
                $hits,
                "job {$name} must not run the whole quality-gate suite"
            );
        }
    }

    public function test_composer_scripts_expose_invokable_php_checks(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $this->assertIsArray($composer);
        $scripts = $composer['scripts'] ?? [];
        $this->assertArrayHasKey('test', $scripts);
        $this->assertContains('@php artisan test', $scripts['test']);
        $this->assertSame(
            '@php vendor/bin/psalm --taint-analysis --no-cache --memory-limit=1G',
            $scripts['sast']
        );
        $this->assertArrayNotHasKey(
            'audit',
            $scripts,
            'do not override native composer audit with a wrapping script'
        );
        $this->assertSame('gitleaks detect --source .', $scripts['gitleaks']);
        $this->assertSame('@php vendor/bin/pint --test', $scripts['pint']);
        $this->assertFileExists(base_path('.gitleaks.toml'));
        $this->assertFileExists(base_path('psalm.xml'));
        $psalm = file_get_contents(base_path('psalm.xml'));
        $this->assertNotFalse($psalm);
        $this->assertStringContainsString('<directory name="app"/>', $psalm);
        $this->assertStringContainsString('<directory name="routes"/>', $psalm);
        $this->assertStringContainsString('<directory name="tests"/>', $psalm);
        $this->assertMatchesRegularExpression('/errorLevel="(\d+)"/', $psalm);
        preg_match('/errorLevel="(\d+)"/', $psalm, $level);
        $this->assertLessThan(8, (int) $level[1]);
    }

    /**
     * @return array<string, string>
     */
    private function preCommitHooks(string $yaml): array
    {
        preg_match_all(
            '/^\s+- id: (\S+)\n(?:.*\n)*?^\s+entry: (.+)$/m',
            $yaml,
            $matches,
            PREG_SET_ORDER
        );
        $hooks = [];
        foreach ($matches as $match) {
            $hooks[$match[1]] = trim($match[2]);
        }

        return $hooks;
    }

    /**
     * @return array<string, string>
     */
    private function giteaJobs(string $yaml): array
    {
        $pos = strpos($yaml, "\njobs:\n");
        $this->assertNotFalse($pos, 'workflow must define a jobs: mapping');
        $section = substr($yaml, $pos + strlen("\njobs:\n"));
        $jobs = [];
        $current = null;
        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $id)) {
                $current = $id[1];
                $jobs[$current] = '';

                continue;
            }
            if ($current !== null) {
                $jobs[$current] .= $line."\n";
            }
        }

        return $jobs;
    }

    /**
     * @param  array<string, string>  $jobs
     * @param  list<string>  $checks
     */
    private function firstStageJobName(array $jobs, array $checks): string
    {
        if (isset($jobs['prepare']) && ! in_array('prepare', $checks, true)) {
            return 'prepare';
        }
        foreach (array_keys($jobs) as $name) {
            if (! in_array($name, $checks, true)) {
                return $name;
            }
        }

        return '';
    }
}
