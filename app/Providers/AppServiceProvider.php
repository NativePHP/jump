<?php

namespace App\Providers;

use App\Support\DiscoveredServers;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // App-wide store for the discovered-servers pill (read by the layout on
        // every tab, written by whichever tab is active). A singleton so it
        // persists across native events in the long-lived runtime.
        $this->app->singleton(DiscoveredServers::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
