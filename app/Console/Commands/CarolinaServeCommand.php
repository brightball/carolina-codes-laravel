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

    protected $description = 'Register once with Elixir, then serve Laravel on the PHP development server';

    public function handle(): int
    {
        $this->copyProcessEnv();
        Catalog::registerWithElixir();
        $host = $this->option('host') ?: '[::]';
        $port = $this->option('port') ?: (getenv('PORT') ?: '4022');

        return $this->call('serve', [
            '--host' => $host,
            '--port' => $port,
            '--no-reload' => true,
        ]);
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
