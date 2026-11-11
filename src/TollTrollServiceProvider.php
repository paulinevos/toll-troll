<?php

namespace Vos\TollTroll;

use Illuminate\Support\ServiceProvider;

class TollTrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/toll-troll.php', 'toll-troll');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\BenchmarkCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../config/toll-troll.php' => config_path('toll-troll.php'),
        ], 'toll-troll-config');
    }
}
