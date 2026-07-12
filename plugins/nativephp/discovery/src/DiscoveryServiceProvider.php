<?php

namespace NativePHP\Discovery;

use Illuminate\Support\ServiceProvider;

class DiscoveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Discovery::class, function () {
            return new Discovery;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\PatchShellCommand::class,
            ]);
        }
    }
}
