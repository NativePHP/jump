<?php

/**
 * Exercises the post_compile hook (ConfigureManifestCommand) end-to-end: it
 * runs the artisan command against a throwaway native project and asserts the
 * background / foreground-service entries are added or removed strictly
 * according to the two opt-in flags — and that toggling off is clean.
 */

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

function makeAndroidProject(): string
{
    $dir = sys_get_temp_dir().'/geo-hook-'.uniqid();
    $manifestDir = $dir.'/app/src/main';
    File::ensureDirectoryExists($manifestDir);

    File::put($manifestDir.'/AndroidManifest.xml', <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <manifest xmlns:android="http://schemas.android.com/apk/res/android">
        <uses-permission android:name="android.permission.INTERNET" />
        <application android:label="Test">
            <activity android:name=".MainActivity" />
        </application>
    </manifest>
    XML);

    return $dir;
}

function makeIosProject(): string
{
    $dir = sys_get_temp_dir().'/geo-hook-'.uniqid();
    File::ensureDirectoryExists($dir.'/NativePHP');

    File::put($dir.'/NativePHP/Info.plist', <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
    <plist version="1.0">
    <dict>
        <key>CFBundleName</key>
        <string>Test</string>
    </dict>
    </plist>
    XML);

    return $dir;
}

function androidManifest(string $dir): string
{
    return File::get($dir.'/app/src/main/AndroidManifest.xml');
}

function iosPlist(string $dir): string
{
    return File::get($dir.'/NativePHP/Info.plist');
}

function runHook(string $platform, string $dir): void
{
    test()->artisan('nativephp:geolocation:configure-manifest', [
        '--platform' => $platform,
        '--build-path' => $dir,
    ])->assertSuccessful();
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/geo-hook-*') as $leftover) {
        File::deleteDirectory($leftover);
    }
});

// ---------------------------------------------------------------------------
// Android
// ---------------------------------------------------------------------------

it('injects nothing when both flags are off (foreground-only default)', function () {
    config()->set('nativephp-geolocation.foreground_service', false);
    config()->set('nativephp-geolocation.background_location', false);

    $dir = makeAndroidProject();
    runHook('android', $dir);
    $xml = androidManifest($dir);

    expect($xml)->toContain('android.permission.INTERNET'); // baseline untouched
    expect($xml)->not->toContain('FOREGROUND_SERVICE');
    expect($xml)->not->toContain('ACCESS_BACKGROUND_LOCATION');
    expect($xml)->not->toContain('LocationWatchService');
    expect($xml)->not->toContain('LocationWatchBootReceiver');
});

it('injects the foreground service + FGS permissions when foreground_service is on', function () {
    config()->set('nativephp-geolocation.foreground_service', true);
    config()->set('nativephp-geolocation.background_location', false);

    $dir = makeAndroidProject();
    runHook('android', $dir);
    $xml = androidManifest($dir);

    expect($xml)->toContain('android.permission.FOREGROUND_SERVICE" ');
    expect($xml)->toContain('android.permission.FOREGROUND_SERVICE_LOCATION');
    expect($xml)->toContain('android.permission.POST_NOTIFICATIONS');
    expect($xml)->toContain('com.nativephp.geolocation.LocationWatchService');
    expect($xml)->toContain('android:foregroundServiceType="location"');

    // Not the killed-app / survival tier.
    expect($xml)->not->toContain('ACCESS_BACKGROUND_LOCATION');
    expect($xml)->not->toContain('RECEIVE_BOOT_COMPLETED');
    expect($xml)->not->toContain('LocationWatchBootReceiver');
});

it('injects background survival (and implies the foreground service) when background_location is on', function () {
    config()->set('nativephp-geolocation.foreground_service', false);
    config()->set('nativephp-geolocation.background_location', true);

    $dir = makeAndroidProject();
    runHook('android', $dir);
    $xml = androidManifest($dir);

    // Survival tier.
    expect($xml)->toContain('android.permission.ACCESS_BACKGROUND_LOCATION');
    expect($xml)->toContain('android.permission.RECEIVE_BOOT_COMPLETED');
    expect($xml)->toContain('com.nativephp.geolocation.LocationWatchBootReceiver');
    expect($xml)->toContain('android.intent.action.BOOT_COMPLETED');

    // background implies foreground service.
    expect($xml)->toContain('android.permission.FOREGROUND_SERVICE_LOCATION');
    expect($xml)->toContain('com.nativephp.geolocation.LocationWatchService');
});

it('is idempotent and cleanly removes entries when toggled back off', function () {
    $dir = makeAndroidProject();

    config()->set('nativephp-geolocation.background_location', true);
    runHook('android', $dir);
    runHook('android', $dir); // second run must not duplicate

    $xml = androidManifest($dir);
    expect(substr_count($xml, 'ACCESS_BACKGROUND_LOCATION'))->toBe(1);
    expect(substr_count($xml, 'com.nativephp.geolocation.LocationWatchService'))->toBe(1);

    // Toggle everything off and re-run.
    config()->set('nativephp-geolocation.background_location', false);
    runHook('android', $dir);
    $xml = androidManifest($dir);

    expect($xml)->not->toContain('ACCESS_BACKGROUND_LOCATION');
    expect($xml)->not->toContain('FOREGROUND_SERVICE');
    expect($xml)->not->toContain('LocationWatchService');
    expect($xml)->not->toContain('LocationWatchBootReceiver');
    expect($xml)->toContain('android.permission.INTERNET'); // baseline survives
    expect($xml)->toContain('.MainActivity');               // app entries survive
});

// ---------------------------------------------------------------------------
// iOS
// ---------------------------------------------------------------------------

it('adds the iOS location background mode only when the foreground service is on', function () {
    $dir = makeIosProject();

    config()->set('nativephp-geolocation.foreground_service', false);
    config()->set('nativephp-geolocation.background_location', false);
    runHook('ios', $dir);
    expect(iosPlist($dir))->not->toContain('UIBackgroundModes');

    config()->set('nativephp-geolocation.foreground_service', true);
    runHook('ios', $dir);
    $plist = iosPlist($dir);
    expect($plist)->toContain('UIBackgroundModes');
    expect($plist)->toContain('<string>location</string>');

    // Still valid XML.
    expect(@simplexml_load_string($plist))->not->toBeFalse();
});

it('adds the iOS Always usage string only when background_location is on, and removes it when off', function () {
    $dir = makeIosProject();

    config()->set('nativephp-geolocation.background_location', true);
    runHook('ios', $dir);
    $plist = iosPlist($dir);
    expect($plist)->toContain('NSLocationAlwaysAndWhenInUseUsageDescription');
    expect($plist)->toContain('<string>location</string>'); // implied bg mode

    config()->set('nativephp-geolocation.background_location', false);
    config()->set('nativephp-geolocation.foreground_service', false);
    runHook('ios', $dir);
    $plist = iosPlist($dir);
    expect($plist)->not->toContain('NSLocationAlwaysAndWhenInUseUsageDescription');
    expect($plist)->not->toContain('UIBackgroundModes');
    expect($plist)->toContain('CFBundleName'); // untouched baseline
    expect(@simplexml_load_string($plist))->not->toBeFalse();
});

it('preserves an app-provided Always usage string when background_location is off', function () {
    $dir = makeIosProject();

    // Simulate the core plist merge injecting the app's own purpose string
    // (nativephp.permissions) — needed to satisfy ITMS-90683 even when the
    // app never uses background location.
    $appString = 'App-provided: location is only used while the app is open.';
    File::put($dir.'/NativePHP/Info.plist', str_replace(
        '</dict>',
        "    <key>NSLocationAlwaysAndWhenInUseUsageDescription</key>\n    <string>{$appString}</string>\n</dict>",
        iosPlist($dir),
    ));

    config()->set('nativephp-geolocation.background_location', false);
    config()->set('nativephp-geolocation.foreground_service', false);
    runHook('ios', $dir);
    $plist = iosPlist($dir);

    expect($plist)->toContain($appString); // hook must not strip the app's key
    expect(@simplexml_load_string($plist))->not->toBeFalse();
});
