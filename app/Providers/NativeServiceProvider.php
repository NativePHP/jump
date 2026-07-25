<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\BiometricsServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\Providers\CameraServiceProvider;
use Native\Mobile\Providers\GeolocationServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\ScannerServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use Native\Mobile\Providers\ShareServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;
use NativePHP\Clipboard\ClipboardServiceProvider;
use NativePHP\Discovery\DiscoveryServiceProvider;
use NativePHP\LocalNotifications\LocalNotificationsServiceProvider;
use NativePHP\MediaPlayer\MediaPlayerServiceProvider;
use S2BR\MobileSplashscreen\MobileSplashscreenServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            NativeUIServiceProvider::class,
            DiscoveryServiceProvider::class,
            ClipboardServiceProvider::class,
            BrowserServiceProvider::class,
            ShareServiceProvider::class,
            ScannerServiceProvider::class,
            CameraServiceProvider::class,
            NetworkServiceProvider::class,
            SecureStorageServiceProvider::class,
            BiometricsServiceProvider::class,
            GeolocationServiceProvider::class,
            MediaPlayerServiceProvider::class,
            LocalNotificationsServiceProvider::class
        ];
    }
}
