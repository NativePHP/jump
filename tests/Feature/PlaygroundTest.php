<?php

use App\NativeComponents\Playground;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Events\Geolocation\PermissionStatusReceived;
use Native\Mobile\Testing\Native;

/**
 * The bundled NativePHP Playground: a single scrolling page of ten API demos,
 * each pairing the PHP snippet with a live example of that exact call.
 */
test('the playground mounts as a single page', function () {
    Native::visit('/playground')->assertScreen(Playground\Home::class);
});

test('every demo card renders with its code snippet', function () {
    $screen = Native::visit('/playground');

    foreach ([
        'Camera', 'Gallery', 'Video', 'Location', 'Notifications',
        'Biometrics', 'Device info', 'Flashlight', 'Network', 'Haptics',
    ] as $title) {
        $screen->assertSee($title);
    }

    $screen->assertSee('Camera::getPhoto()')
        ->assertSee('Geolocation::getCurrentPosition()')
        ->assertSee('Biometrics::prompt()')
        ->assertSee('Haptics::vibrate();');
});

test('the haptics demo counts buzzes', function () {
    Native::visit('/playground')
        ->press('vibrate')
        ->assertSet('buzzes', 1);
});

test('an already-granted permission goes straight to a fix, no prompt', function () {
    Native::visit('/playground')
        ->press('getLocation')
        ->assertSet('locating', true)
        ->assertAwaitingNativeEvent(PermissionStatusReceived::class)
        ->emitNative(PermissionStatusReceived::class, [
            'location' => 'granted', 'coarseLocation' => 'granted', 'fineLocation' => 'granted',
        ])
        // Granted → no requestPermissions() round-trip, straight to a position.
        ->assertAwaitingNativeEvent(LocationReceived::class)
        ->assertNotAwaitingNativeEvent(PermissionRequestResult::class)
        ->emitNative(LocationReceived::class, [
            'success' => true, 'latitude' => 40.7128, 'longitude' => -74.006, 'accuracy' => 12.0,
        ])
        ->assertSet('locating', false)
        ->assertSet('latitude', 40.7128)
        // The transient "Getting a fix…" status clears once coordinates arrive.
        ->assertSet('locationStatus', '');
});

test('an undetermined permission triggers the request prompt', function () {
    Native::visit('/playground')
        ->press('getLocation')
        // The plugin reports notDetermined as 'denied' — the one promptable state.
        ->emitNative(PermissionStatusReceived::class, [
            'location' => 'denied', 'coarseLocation' => 'denied', 'fineLocation' => 'denied',
        ])
        ->assertAwaitingNativeEvent(PermissionRequestResult::class)
        ->assertSet('locationStatus', 'Requesting permission…');
});

test('a permanently-denied permission offers Settings instead of hanging', function () {
    Native::visit('/playground')
        ->press('getLocation')
        ->emitNative(PermissionStatusReceived::class, [
            'location' => 'permanently_denied', 'coarseLocation' => 'permanently_denied', 'fineLocation' => 'permanently_denied',
        ])
        ->assertSet('locating', false)
        ->assertSet('locationBlocked', true);
});

test('the biometrics demo tracks the prompt state', function () {
    Native::visit('/playground')
        ->press('authenticate')
        ->assertSet('bioState', 'prompting');
});
