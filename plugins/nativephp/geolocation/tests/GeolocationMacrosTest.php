<?php

/**
 * The geolocation test vocabulary this plugin registers on the FakeBridge
 * (withBackgroundWatch / assertLocationRequested / assertWatchStarted / …)
 * — the sugar app developers use instead of raw bridge method strings.
 *
 * Skipped on cores whose FakeBridge predates macro support.
 */

use Native\Mobile\Geolocation;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (! method_exists(FakeBridge::class, 'macro')) {
        $this->markTestSkipped('This core\'s FakeBridge does not support macros.');
    }

    $this->bridge = Native::fakeBridge();
});

describe('withBackgroundWatch()', function () {
    it('reports the active watch through backgroundWatchStatus()', function () {
        $this->bridge->withBackgroundWatch('watch-bg', [], ['fineAccuracy' => true]);

        $status = (new Geolocation)->backgroundWatchStatus();

        expect($status)->not->toBeNull();
        expect($status)->not->toHaveKey('active');
        expect($status['id'])->toBe('watch-bg');
        expect($status['fineAccuracy'])->toBeTrue();
    });

    it('serves buffered fixes through the drain-cursor cycle', function () {
        $this->bridge->withBackgroundWatch('watch-bg', [
            ['latitude' => 40.7, 'longitude' => -74.0, 'accuracy' => 5, 'timestamp' => 1],
            ['latitude' => 40.8, 'longitude' => -74.1, 'accuracy' => 5, 'timestamp' => 2],
        ]);

        $first = (new Geolocation)->drainWatch('watch-bg');

        expect($first['fixes'])->toHaveCount(2);
        expect($first['fixes'][0]['latitude'])->toBe(40.7);

        $second = (new Geolocation)->drainWatch('watch-bg', $first['cursor']);

        expect($second['fixes'])->toBe([]);
    });

    it('leaves other watch ids with an empty buffer', function () {
        $this->bridge->withBackgroundWatch('watch-bg', [
            ['latitude' => 40.7, 'longitude' => -74.0, 'accuracy' => 5, 'timestamp' => 1],
        ]);

        expect((new Geolocation)->drainWatch('other'))->toBe(['fixes' => [], 'cursor' => 0, 'size' => 0]);
    });
});

describe('assertLocationRequested()', function () {
    it('passes after a position request', function () {
        (new Geolocation)->getCurrentPosition()->id('loc-1')->get();

        $this->bridge->assertLocationRequested();
    });

    it('fails when no position was requested', function () {
        expect(fn () => $this->bridge->assertLocationRequested())
            ->toThrow(AssertionFailedError::class);
    });
});

describe('permission assertions', function () {
    it('assertLocationPermissionsChecked() passes after checkPermissions()', function () {
        (new Geolocation)->checkPermissions()->id('perm-check')->get();

        $this->bridge->assertLocationPermissionsChecked();
    });

    it('assertLocationPermissionsRequested() passes after requestPermissions()', function () {
        (new Geolocation)->requestPermissions()->id('perm-request')->get();

        $this->bridge->assertLocationPermissionsRequested();
    });

    it('a check does not satisfy assertLocationPermissionsRequested(), naming what was called', function () {
        (new Geolocation)->checkPermissions()->id('perm-check')->get();

        expect(fn () => $this->bridge->assertLocationPermissionsRequested())
            ->toThrow(AssertionFailedError::class, 'Geolocation.CheckPermissions');
    });
});

describe('assertWatchStarted()', function () {
    it('passes for a foreground watch', function () {
        (new Geolocation)->watchPosition()->id('watch-1')->start();

        $this->bridge->assertWatchStarted()->assertWatchStarted('watch-1');
    });

    it('passes for a background watch', function () {
        (new Geolocation)->watchPosition()->id('watch-bg')->background()->start();

        $this->bridge->assertWatchStarted('watch-bg');
    });

    it('fails when no watch was started', function () {
        expect(fn () => $this->bridge->assertWatchStarted())
            ->toThrow(AssertionFailedError::class);
    });

    it('fails for the wrong id, naming the watches that were started', function () {
        (new Geolocation)->watchPosition()->id('watch-1')->start();

        expect(fn () => $this->bridge->assertWatchStarted('other'))
            ->toThrow(AssertionFailedError::class, 'watch-1');
    });
});

describe('assertWatchCleared()', function () {
    it('passes after clearWatch()', function () {
        (new Geolocation)->clearWatch('watch-1');

        $this->bridge->assertWatchCleared()->assertWatchCleared('watch-1');
    });

    it('passes after stopBackgroundWatch()', function () {
        (new Geolocation)->stopBackgroundWatch('watch-bg');

        $this->bridge->assertWatchCleared('watch-bg');
    });

    it('fails when nothing was cleared, naming what was', function () {
        expect(fn () => $this->bridge->assertWatchCleared('watch-1'))
            ->toThrow(AssertionFailedError::class, '(none)');
    });
});
