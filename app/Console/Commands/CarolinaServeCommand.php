<?php

namespace App\Console\Commands;

use App\Catalog;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'carolina:serve')]
class CarolinaServeCommand extends Command
{
    protected $signature = 'carolina:serve
        {--host= : The host address to serve the application on}
        {--port= : The port to serve the application on}';

    protected $description = 'Serve Laravel and register once with Elixir without blocking the listener';

    private static bool $registrationSpawned = false;

    public function handle(): int
    {
        $this->copyProcessEnv();
        $this->registerWithoutBlockingListen();
        $host = $this->option('host') ?: '[::]';
        $port = $this->option('port') ?: (getenv('PORT') ?: '4022');

        return $this->call('serve', [
            '--host' => $host,
            '--port' => $port,
            '--no-reload' => true,
        ]);
    }

    /**
     * The CMS POST can sit until Catalog::REGISTER_TIMEOUT_SECONDS. Detach it
     * so php -S is listening before that timeout, and so a failed POST cannot
     * stop the server. Empty CAROLINA_URL or token skips the spawn entirely.
     */
    private function registerWithoutBlockingListen(): void
    {
        $url = getenv('CAROLINA_URL') ?: '';
        $token = getenv('POLYGLOT_REGISTER_TOKEN') ?: '';
        if ($url === '' || $token === '') {
            return;
        }
        if (self::$registrationSpawned) {
            return;
        }
        self::$registrationSpawned = true;

        $autoload = base_path('vendor/autoload.php');
        $code = 'require '.var_export($autoload, true).'; \\App\\Catalog::registerWithElixir();';
        $proc = proc_open(
            ['/bin/sh', '-c', '"$0" -r "$1" >/dev/null 2>&1 &', PHP_BINARY, $code],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            base_path()
        );
        if (is_resource($proc)) {
            proc_close($proc);
        }
    }

    /**
     * This PHP build uses variables_order=GPCS, so $_ENV is empty. artisan serve
     * copies $_ENV into the php -S child; fill it from the real process environment
     * so DATABASE_URL / PHPRC / CAROLINA_URL reach catalog handlers.
     */
    private function copyProcessEnv(): void
    {
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                $_ENV[$key] = $value;
            }
        }
        foreach (['DATABASE_URL', 'CAROLINA_URL', 'POLYGLOT_REGISTER_TOKEN', 'PUBLIC_BASE_URL', 'PORT', 'PHPRC', 'PATH', 'APP_KEY', 'APP_ENV'] as $key) {
            $val = getenv($key);
            if ($val !== false) {
                $_ENV[$key] = $val;
            }
        }
    }
}
