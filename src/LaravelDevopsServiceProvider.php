<?php

namespace Microsomes\LaravelDevops;

use Illuminate\Support\ServiceProvider;
use Microsomes\LaravelDevops\Console\DockerInitCommand;

class LaravelDevopsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/docker-scaffold.php',
            'docker-scaffold'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DockerInitCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/docker-scaffold.php' => config_path('docker-scaffold.php'),
            ], 'docker-scaffold-config');
        }
    }
}
