<?php

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ServeCommand::$passthroughVariables = array_merge(
            ServeCommand::$passthroughVariables,
            [
                'DATABASE_URL',
                'CAROLINA_URL',
                'POLYGLOT_REGISTER_TOKEN',
                'PUBLIC_BASE_URL',
                'PORT',
                'PHPRC',
            ]
        );
    }
}
