<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Geolocation;
use Native\Mobile\Facades\System;
use NativePHP\Geolocation\Concerns\TracksBackgroundLocation;

class GeolocationDemo extends NativeComponent
{
    // Background watch lifecycle (id/cursor handle, re-attach, drain,
    // live listener, trail) comes from the plugin trait; this screen
    // only wires the mount/onResume hooks and demo-specific UI state.
    use TracksBackgroundLocation;

    /** @var array<string, string> */
    public array $permissions = [];

    public ?float $latitude = null;

    public ?float $longitude = null;

    public ?float $accuracy = null;

    public ?string $provider = null;

    public string $status = '';

    public bool $locating = false;

    // Streaming watch state
    public ?string $watchId = null;

    public int $watchUpdates = 0;

    public ?float $watchLatitude = null;

    public ?float $watchLongitude = null;

    public ?float $watchAccuracy = null;

    /** Camera follows incoming fixes when on; off = user owns pan/zoom. */
    public bool $bgFollow = false;

    /** Map camera zoom level (web-mercator scale, ~10 city → ~18 street). */
    public float $bgZoom = 15;

    /** Native buffer size — the write-side truth (grows per buffered fix). */
    public ?int $bgBufferBytes = null;

    public ?string $bgAuthorization = null;

    /** 'yes' when the built app declares the location background mode. */
    public ?string $bgBackgroundMode = null;

    /** Environment gates that block background delivery (null = none). */
    public ?string $bgGates = null;

    /**
     * Diagnostic heartbeat: if bufferBytes climbs with each fix, native
     * appends work and any gap is delivery-side; if it flatlines while
     * live fixes stream, the buffer write path is broken.
     */
    #[Poll(5000)]
    public function refreshBufferStats(): void
    {
        if ($this->bgWatchId === null) {
            return;
        }

        if ($watch = Geolocation::backgroundWatchStatus()) {
            $this->bgBufferBytes = $watch['bufferBytes'] ?? null;
            $this->bgAuthorization = $watch['authorization'] ?? null;
            $this->bgBackgroundMode = isset($watch['backgroundMode'])
                ? ($watch['backgroundMode'] ? 'yes' : 'MISSING')
                : null;

            $gates = [];
            if ($watch['lowPowerMode'] ?? false) {
                $gates[] = 'Low Power Mode';
            }
            if (($watch['backgroundRefresh'] ?? 'available') !== 'available') {
                $gates[] = 'Background App Refresh '.($watch['backgroundRefresh'] ?? 'off');
            }
            $this->bgGates = $gates === [] ? null : implode(' · ', $gates);
        }
    }

    public function navTitle(): string
    {
        return 'Geolocation';
    }

    public function mount(): void
    {
        $this->attachBackgroundWatch();
    }

    public function onResume(): void
    {
        $this->syncBackgroundWatch();
    }

    public function checkPermissions(): void
    {
        Geolocation::checkPermissions()
            ->permissionStatusReceived(function ($event) {
                $this->permissions = [
                    'location' => $event->location,
                    'coarse' => $event->coarseLocation,
                    'fine' => $event->fineLocation,
                ];
            });
    }

    public function requestPermissions(): void
    {
        Geolocation::requestPermissions()
            ->permissionRequestResult(function ($event) {
                $this->permissions = [
                    'location' => $event->location,
                    'coarse' => $event->coarseLocation,
                    'fine' => $event->fineLocation,
                ];

                if ($event->location === 'denied_forever') {
                    $this->status = 'Permission permanently denied — open Settings to enable location.';
                } else {
                    $this->status = '';
                }
            });
    }

    public function getCurrentPosition(): void
    {
        $this->locating = true;

        Geolocation::getCurrentPosition(true)
            ->locationReceived(function ($event) {
                $this->locating = false;

                if (! $event->success) {
                    $this->status = $event->error ?: 'Could not determine location';

                    return;
                }

                $this->status = '';
                $this->latitude = $event->latitude;
                $this->longitude = $event->longitude;
                $this->accuracy = $event->accuracy;
                $this->provider = $event->provider;
            });
    }

    /**
     * Streaming location: locationUpdated() is a persistent listener that
     * fires for every fix until the watch is cleared (or the screen unmounts,
     * which stops it automatically).
     */
    public function startWatch(): void
    {
        if ($this->watchId !== null) {
            return;
        }

        $this->watchUpdates = 0;

        $this->watchId = Geolocation::watchPosition(fineAccuracy: true)
            ->interval(500)
            ->minDistance(3)
            ->locationUpdated(function ($event) {
                $this->watchUpdates++;
                $this->watchLatitude = $event->latitude;
                $this->watchLongitude = $event->longitude;
                $this->watchAccuracy = $event->accuracy;
            })
            ->getId();
    }

    public function stopWatch(): void
    {
        if ($this->watchId === null) {
            return;
        }

        Geolocation::clearWatch($this->watchId);
        $this->watchId = null;
    }

    public function openSettings(): void
    {
        System::appSettings();
    }

    public function render(): View
    {
        return view('native.playground.geolocation-demo');
    }
}
