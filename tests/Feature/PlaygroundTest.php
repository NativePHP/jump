<?php

use App\NativeComponents\Playground;
use Native\Mobile\Testing\Native;

/**
 * The bundled NativePHP Playground (ported from the kitchensink app): every
 * screen mounts, hub rows point at registered /playground/* routes, and the
 * Home pill exits back to the Jump shell.
 */
test('every playground screen mounts', function (string $uri, string $component) {
    Native::visit($uri)->assertScreen($component);
})->with([
    ['/playground', Playground\Home::class],
    ['/playground/media', Playground\MediaHub::class],
    ['/playground/system', Playground\SystemHub::class],
    ['/playground/notify', Playground\NotifyHub::class],
    ['/playground/media/camera', Playground\CameraDemo::class],
    ['/playground/media/gallery', Playground\GalleryDemo::class],
    ['/playground/media/video', Playground\VideoDemo::class],
    ['/playground/media/microphone', Playground\MicrophoneDemo::class],
    ['/playground/media/scanner', Playground\ScannerDemo::class],
    ['/playground/system/biometrics', Playground\BiometricsDemo::class],
    ['/playground/system/device', Playground\DeviceDemo::class],
    ['/playground/system/network', Playground\NetworkDemo::class],
    ['/playground/system/haptics', Playground\HapticsDemo::class],
    ['/playground/system/flashlight', Playground\FlashlightDemo::class],
    ['/playground/system/secure-storage', Playground\SecureStorageDemo::class],
    ['/playground/system/browser', Playground\BrowserDemo::class],
    ['/playground/system/sleep', Playground\SleepDemo::class],
    ['/playground/system/wallet', Playground\WalletDemo::class],
    ['/playground/system/nfc', Playground\NfcDemo::class],
    ['/playground/notify/alert', Playground\AlertDemo::class],
    ['/playground/notify/toast', Playground\ToastDemo::class],
    ['/playground/notify/share', Playground\ShareDemo::class],
    ['/playground/notify/local', Playground\LocalNotificationsDemo::class],
]);

test('every hub demo row navigates to a registered route', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => '/'.ltrim($r->uri(), '/'));

    foreach ([Playground\MediaHub::class, Playground\SystemHub::class, Playground\NotifyHub::class] as $hub) {
        foreach ((new $hub)->groups() as $group) {
            foreach ($group['demos'] as $demo) {
                expect($routes)->toContain($demo['url']);
            }
        }
    }

    foreach ((new Playground\Home)->featured() as $demo) {
        expect($routes)->toContain($demo['url']);
    }
});

test('the exit pill navigates back to the Jump shell', function () {
    Native::visit('/playground')
        ->press('exitPlayground')
        ->assertNavigatedTo('/');
});
