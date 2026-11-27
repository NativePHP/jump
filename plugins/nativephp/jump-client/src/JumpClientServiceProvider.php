<?php

namespace NativePHP\JumpClient;

use Illuminate\Support\ServiceProvider;

class JumpClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JumpClient::class, function () {
            return new JumpClient;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\PatchShellCommand::class,
            ]);
        }
    }
}
