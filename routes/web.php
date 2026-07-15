<?php

use App\NativeComponents\Builds;
use App\NativeComponents\Docs;
use App\NativeComponents\Home;
use App\NativeComponents\Layouts\AppStackLayout;
use App\NativeComponents\Layouts\JumpTabsLayout;
use App\NativeComponents\Layouts\PlaygroundTabsLayout;
use App\NativeComponents\Playground;
use App\NativeComponents\Settings;
use App\NativeComponents\Videos;
use Illuminate\Support\Facades\Route;

// Jump shell: native bottom-tab chrome over the four top-level screens.
Route::nativeGroup(JumpTabsLayout::class, function () {
    Route::native('/', Home::class)->name('home');
    Route::native('/builds', Builds::class)->name('builds');
    Route::native('/docs', Docs::class)->name('docs');
    Route::native('/docs/mobile/{version}/{section}/{page}', Docs::class)->name('docs.page');
    Route::native('/videos', Videos::class)->name('videos');
});

Route::nativeGroup(AppStackLayout::class, function () {
    Route::native('/settings', Settings::class)->name('settings');
});

/*
 * The bundled NativePHP Playground (ported from the kitchensink app). One
 * shared layout for the four tab roots AND their pushed demo screens keeps
 * every navigation inside the native TabView's per-tab NavigationStack (demo
 * URIs are prefix-children of their tab's URL), so entering a demo is a real
 * native push and going back is a real native pop. The push/data-messages
 * demos are omitted — they need the firebase plugin, which Jump doesn't bundle.
 */
Route::nativeGroup(PlaygroundTabsLayout::class, function () {
    Route::native('/playground', Playground\Home::class)->name('playground');
    Route::native('/playground/media', Playground\MediaHub::class);
    Route::native('/playground/system', Playground\SystemHub::class);
    Route::native('/playground/notify', Playground\NotifyHub::class);

    Route::native('/playground/media/camera', Playground\CameraDemo::class);
    Route::native('/playground/media/gallery', Playground\GalleryDemo::class);
    Route::native('/playground/media/video', Playground\VideoDemo::class);
    Route::native('/playground/media/microphone', Playground\MicrophoneDemo::class);
    Route::native('/playground/media/scanner', Playground\ScannerDemo::class);

    Route::native('/playground/system/biometrics', Playground\BiometricsDemo::class);
    Route::native('/playground/system/geolocation', Playground\GeolocationDemo::class);
    Route::native('/playground/system/device', Playground\DeviceDemo::class);
    Route::native('/playground/system/network', Playground\NetworkDemo::class);
    Route::native('/playground/system/haptics', Playground\HapticsDemo::class);
    Route::native('/playground/system/flashlight', Playground\FlashlightDemo::class);
    Route::native('/playground/system/secure-storage', Playground\SecureStorageDemo::class);
    Route::native('/playground/system/browser', Playground\BrowserDemo::class);
    Route::native('/playground/system/sleep', Playground\SleepDemo::class);
    Route::native('/playground/system/wallet', Playground\WalletDemo::class);
    Route::native('/playground/system/nfc', Playground\NfcDemo::class);

    Route::native('/playground/notify/alert', Playground\AlertDemo::class);
    Route::native('/playground/notify/toast', Playground\ToastDemo::class);
    Route::native('/playground/notify/share', Playground\ShareDemo::class);
    Route::native('/playground/notify/local', Playground\LocalNotificationsDemo::class);
});
