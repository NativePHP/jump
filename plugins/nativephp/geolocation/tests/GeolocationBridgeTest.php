<?php

/**
 * Contract tests for the Geolocation facade (which ships in nativephp/mobile)
 * against THIS plugin's bridge functions, driven through the NativePHP
 * FakeBridge. They pin the PHP → native contract: calling the facade fires
 * the right bridge function with the right payload and decodes native
 * responses as callers expect. Requires nativephp/mobile, so this file is
 * bound to the Testbench TestCase.
 *
 * Run with: ./test-plugins.sh --element geolocation
 */

use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\LocationUpdated;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Events\Geolocation\PermissionStatusReceived;
use Native\Mobile\Geolocation;
use Native\Mobile\Testing\Native;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->bridge = Native::fakeBridge();
});

describe('getCurrentPosition()', function () {
    it('fires Geolocation.GetCurrentPosition with id, event and fineAccuracy', function () {
        (new Geolocation)->getCurrentPosition()->fineAccuracy()->id('loc-1')->get();

        $this->bridge->assertCalled('Geolocation.GetCurrentPosition', function (array $p) {
            expect($p['id'])->toBe('loc-1');
            expect($p['event'])->toBe(LocationReceived::class);
            expect($p['fineAccuracy'])->toBeTrue();

            return true;
        });
    });

    it('passes the fineAccuracy argument through from the facade', function () {
        (new Geolocation)->getCurrentPosition(false)->id('loc-coarse')->get();

        $this->bridge->assertCalled('Geolocation.GetCurrentPosition', function (array $p) {
            expect($p['id'])->toBe('loc-coarse');
            expect($p['fineAccuracy'])->toBeFalse();

            return true;
        });
    });

    it('generates an id when none is supplied', function () {
        (new Geolocation)->getCurrentPosition()->get();

        $calls = $this->bridge->callsTo('Geolocation.GetCurrentPosition');
        expect($calls)->toHaveCount(1);
        expect($calls[0]['params']['id'])->not->toBeEmpty();
    });
});

describe('permissions', function () {
    it('checks permissions via Geolocation.CheckPermissions', function () {
        (new Geolocation)->checkPermissions()->id('perm-check')->get();

        $this->bridge->assertCalled('Geolocation.CheckPermissions', function (array $p) {
            expect($p['id'])->toBe('perm-check');
            expect($p['event'])->toBe(PermissionStatusReceived::class);

            return true;
        });
    });

    it('requests permissions via Geolocation.RequestPermissions', function () {
        (new Geolocation)->requestPermissions()->id('perm-request')->get();

        $this->bridge->assertCalled('Geolocation.RequestPermissions', function (array $p) {
            expect($p['id'])->toBe('perm-request');
            expect($p['event'])->toBe(PermissionRequestResult::class);

            return true;
        });
    });
});

describe('watchPosition()', function () {
    it('starts a foreground watch via Geolocation.WatchPosition', function () {
        (new Geolocation)->watchPosition(true)
            ->id('watch-1')
            ->interval(2000)
            ->minDistance(5)
            ->start();

        $this->bridge->assertCalled('Geolocation.WatchPosition', function (array $p) {
            expect($p['id'])->toBe('watch-1');
            expect($p['event'])->toBe(LocationUpdated::class);
            expect($p['fineAccuracy'])->toBeTrue();
            expect($p['interval'])->toBe(2000);
            // json_encode(5.0) round-trips to an int through the bridge, so
            // compare loosely on the numeric value.
            expect($p['minDistance'])->toEqual(5);

            return true;
        });
    });

    it('starts a background watch via Geolocation.StartBackgroundWatch', function () {
        (new Geolocation)->watchPosition()
            ->id('watch-bg')
            ->background()
            ->start();

        $this->bridge
            ->assertCalled('Geolocation.StartBackgroundWatch', function (array $p) {
                expect($p['id'])->toBe('watch-bg');

                return true;
            })
            ->assertNotCalled('Geolocation.WatchPosition');
    });
});

describe('clearWatch()', function () {
    it('fires Geolocation.ClearWatch with the watch id', function () {
        (new Geolocation)->clearWatch('watch-1');

        $this->bridge->assertCalled('Geolocation.ClearWatch', function (array $p) {
            expect($p['id'])->toBe('watch-1');

            return true;
        });
    });
});

describe('stopBackgroundWatch()', function () {
    it('fires Geolocation.StopBackgroundWatch with id and clearBuffer', function () {
        (new Geolocation)->stopBackgroundWatch('watch-bg', true);

        $this->bridge->assertCalled('Geolocation.StopBackgroundWatch', function (array $p) {
            expect($p['id'])->toBe('watch-bg');
            expect($p['clearBuffer'])->toBeTrue();

            return true;
        });
    });

    it('defaults clearBuffer to false', function () {
        (new Geolocation)->stopBackgroundWatch('watch-bg');

        $this->bridge->assertCalled('Geolocation.StopBackgroundWatch', function (array $p) {
            expect($p['clearBuffer'])->toBeFalse();

            return true;
        });
    });
});

describe('drainWatch()', function () {
    it('fires Geolocation.DrainWatchBuffer and decodes the native buffer', function () {
        $this->bridge->respondTo('Geolocation.DrainWatchBuffer', [
            'fixes' => [
                ['latitude' => 40.7, 'longitude' => -74.0, 'accuracy' => 5, 'timestamp' => 1],
                ['latitude' => 40.8, 'longitude' => -74.1, 'accuracy' => 5, 'timestamp' => 2],
            ],
            'cursor' => 128,
            'size' => 256,
        ]);

        $result = (new Geolocation)->drainWatch('watch-bg', 64);

        $this->bridge->assertCalled('Geolocation.DrainWatchBuffer', function (array $p) {
            expect($p['id'])->toBe('watch-bg');
            expect($p['cursor'])->toBe(64);

            return true;
        });

        expect($result['fixes'])->toHaveCount(2);
        expect($result['fixes'][0]['latitude'])->toBe(40.7);
        expect($result['cursor'])->toBe(128);
        expect($result['size'])->toBe(256);
    });

    it('returns an empty buffer when nothing is scripted', function () {
        $result = (new Geolocation)->drainWatch('watch-bg', 64);

        $this->bridge->assertCalled('Geolocation.DrainWatchBuffer');
        expect($result)->toBe(['fixes' => [], 'cursor' => 64, 'size' => 0]);
    });
});

describe('trimWatch()', function () {
    it('fires Geolocation.TrimWatchBuffer with id and upTo', function () {
        (new Geolocation)->trimWatch('watch-bg', 128);

        $this->bridge->assertCalled('Geolocation.TrimWatchBuffer', function (array $p) {
            expect($p['id'])->toBe('watch-bg');
            expect($p['upTo'])->toBe(128);

            return true;
        });
    });
});

describe('backgroundWatchStatus()', function () {
    it('returns the active watch with the active flag stripped', function () {
        $this->bridge->respondTo('Geolocation.BackgroundWatchStatus', [
            'active' => true,
            'id' => 'watch-bg',
            'event' => LocationUpdated::class,
            'minDistance' => 5.0,
            'fineAccuracy' => true,
            'bufferBytes' => 256,
        ]);

        $status = (new Geolocation)->backgroundWatchStatus();

        $this->bridge->assertCalled('Geolocation.BackgroundWatchStatus');
        expect($status)->not->toBeNull();
        expect($status)->not->toHaveKey('active');
        expect($status['id'])->toBe('watch-bg');
        expect($status['bufferBytes'])->toBe(256);
    });

    it('returns null when no background watch is active', function () {
        $this->bridge->respondTo('Geolocation.BackgroundWatchStatus', ['active' => false]);

        expect((new Geolocation)->backgroundWatchStatus())->toBeNull();
        $this->bridge->assertCalled('Geolocation.BackgroundWatchStatus');
    });
});
