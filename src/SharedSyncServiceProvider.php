<?php

namespace Cslash\SharedSync;

use Illuminate\Support\ServiceProvider;
use Cslash\SharedSync\Commands\DeployCommand;
use Cslash\SharedSync\Commands\TestConnectionCommand;
use Cslash\SharedSync\Commands\LsCommand;
use Cslash\SharedSync\Commands\DiffCommand;

class SharedSyncServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/sharedsync.php', 'sharedsync');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DeployCommand::class,
                TestConnectionCommand::class,
                LsCommand::class,
                DiffCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/Config/sharedsync.php' => config_path('sharedsync.php'),
            ], 'sharedsync-config');
        }
    }
}
