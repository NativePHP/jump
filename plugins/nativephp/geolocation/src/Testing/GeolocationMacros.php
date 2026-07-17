<?php

namespace NativePHP\Geolocation\Testing;

use Native\Mobile\Events\Geolocation\LocationUpdated;
use Native\Mobile\Testing\FakeBridge;
use PHPUnit\Framework\Assert;

/**
 * Geolocation test vocabulary for the NativePHP testing suite, registered as
 * FakeBridge macros so app tests read in location terms instead of raw
 * bridge method strings:
 *
 *     Native::fakeBridge()->withBackgroundWatch('delivery-run', [
 *         ['latitude' => 40.7, 'longitude' => -74.0, 'accuracy' => 5, 'timestamp' => 1],
 *     ]);
 *
 *     Native::test(TrackOrder::class)
 *         ->tap('locate')
 *         ->assertLocationRequested();
 *
 * One-shot positions and permission results arrive as async events
 * (LocationReceived etc.) that the bridge never answers synchronously, so
 * those flows get assert* vocabulary only — no with* scripting.
 *
 * Registered by GeolocationServiceProvider when the app is running unit
 * tests on a core whose FakeBridge supports macros.
 */
class GeolocationMacros
{
    public static function register(): void
    {
        /**
         * Fake an active background watch. backgroundWatchStatus() reports
         * it ($extra overrides the defaults) and drainWatch() serves $fixes
         * through the real drain cycle: persist the returned cursor and the
         * next drain only returns what's new. Cursors count fixes here, not
         * bytes — the shape callers decode is identical.
         */
        FakeBridge::macro('withBackgroundWatch', function (string $id, array $fixes = [], array $extra = []) {
            $this->respondTo('Geolocation.BackgroundWatchStatus', array_merge([
                'active' => true,
                'id' => $id,
                'event' => LocationUpdated::class,
                'minDistance' => 0,
                'fineAccuracy' => false,
                'bufferBytes' => count($fixes),
            ], $extra));

            return $this->respondTo('Geolocation.DrainWatchBuffer', function (array $params) use ($id, $fixes) {
                if (($params['id'] ?? null) !== $id) {
                    return null;
                }

                return [
                    'fixes' => array_slice($fixes, (int) ($params['cursor'] ?? 0)),
                    'cursor' => count($fixes),
                    'size' => count($fixes),
                ];
            });
        });

        /** Assert a one-shot position was requested (getCurrentPosition()). */
        FakeBridge::macro('assertLocationRequested', function () {
            return $this->assertCalled('Geolocation.GetCurrentPosition');
        });

        /** Assert the location permission status was checked (no prompt). */
        FakeBridge::macro('assertLocationPermissionsChecked', function () {
            return $this->assertCalled('Geolocation.CheckPermissions');
        });

        /** Assert the location permission prompt was requested. */
        FakeBridge::macro('assertLocationPermissionsRequested', function () {
            return $this->assertCalled('Geolocation.RequestPermissions');
        });

        /**
         * Assert a location watch was started — foreground or background,
         * any watch, or exactly $id when given.
         */
        FakeBridge::macro('assertWatchStarted', function (?string $id = null) {
            $started = array_map(
                fn (array $call) => $call['params']['id'] ?? '',
                [
                    ...$this->callsTo('Geolocation.WatchPosition'),
                    ...$this->callsTo('Geolocation.StartBackgroundWatch'),
                ]
            );

            if ($id === null) {
                Assert::assertNotEmpty($started, 'Expected a location watch to be started, but none was.');

                return $this;
            }

            Assert::assertContains(
                $id,
                $started,
                "Expected watch [{$id}] to be started. Started: "
                    .($started === [] ? '(none)' : '['.implode('], [', $started).']')
            );

            return $this;
        });

        /**
         * Assert a location watch was stopped — clearWatch() or
         * stopBackgroundWatch(), any watch, or exactly $id when given.
         */
        FakeBridge::macro('assertWatchCleared', function (?string $id = null) {
            $cleared = array_map(
                fn (array $call) => $call['params']['id'] ?? '',
                [
                    ...$this->callsTo('Geolocation.ClearWatch'),
                    ...$this->callsTo('Geolocation.StopBackgroundWatch'),
                ]
            );

            if ($id === null) {
                Assert::assertNotEmpty($cleared, 'Expected a location watch to be cleared, but none was.');

                return $this;
            }

            Assert::assertContains(
                $id,
                $cleared,
                "Expected watch [{$id}] to be cleared. Cleared: "
                    .($cleared === [] ? '(none)' : '['.implode('], [', $cleared).']')
            );

            return $this;
        });
    }
}
