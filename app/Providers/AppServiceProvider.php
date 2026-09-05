<?php

namespace App\Providers;

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
        \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables = array_merge(
            \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables,
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
