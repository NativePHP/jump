<?php

namespace Native\Mobile\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Geolocation;
use Native\Mobile\Testing\FakeBridge;
use NativePHP\Geolocation\Commands\ConfigureManifestCommand;
use NativePHP\Geolocation\Geolocation as ExtendedGeolocation;
use NativePHP\Geolocation\Testing\GeolocationMacros;

class GeolocationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Opt-in flags for the background/foreground-service permissions the
        // build-time hook layers into the native manifests. Merged (not just
        // published) so config('nativephp-geolocation.*') resolves even when
        // the consuming app has not published the file.
        $this->mergeConfigFrom(
            __DIR__.'/../config/nativephp-geolocation.php',
            'nativephp-geolocation',
        );

        // The plugin's extended API (position sharing) is bound to the CORE
        // key: the Geolocation facade accessor and app(Geolocation::class)
        // both resolve through the container, so consumers transparently get
        // the extension.
        $this->app->singleton(Geolocation::class, function () {
            return new ExtendedGeolocation;
        });

        // Test sugar (assertLocationRequested() etc.) — only under a test runner, and
        // only on a core whose FakeBridge is macroable (the method_exists
        // guard keeps older v4 and v3 cores fatal-free).
        if ($this->app->runningUnitTests()
            && class_exists(FakeBridge::class)
            && method_exists(FakeBridge::class, 'macro')) {
            GeolocationMacros::register();
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nativephp-geolocation.php' => config_path('nativephp-geolocation.php'),
            ], 'nativephp-geolocation-config');

            // The build-time hook invoked from nativephp.json (post_compile).
            $this->commands([
                ConfigureManifestCommand::class,
            ]);
        }
    }
}