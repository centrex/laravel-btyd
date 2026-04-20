<?php

declare(strict_types = 1);

namespace Centrex\Btyd;

use Centrex\Btyd\Commands\{BtydCommand, FitBtydParams};
use Illuminate\Support\ServiceProvider;

class BtydServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('btyd.php'),
            ], 'btyd-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'btyd-migrations');

            $this->commands([
                BtydCommand::class,
                FitBtydParams::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'btyd');

        $this->app->singleton('btyd', fn (): Btyd => new Btyd());
        $this->app->singleton(Btyd::class, fn (): Btyd => new Btyd());
    }
}
