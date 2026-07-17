<?php

namespace NativePHP\Geolocation;

use DateTimeInterface;
use Native\Mobile\Geolocation as BaseGeolocation;

/**
 * Plugin extension of the core Geolocation API.
 *
 * Bound to the Native\Mobile\Geolocation container key by
 * GeolocationServiceProvider, so both the Geolocation facade and
 * app(Native\Mobile\Geolocation::class) resolve this class. Adds
 * position sharing (a Strava-style live-location beacon) on top of
 * the background watch — see PendingSharedLocationWatch and
 * SHARE_README.md.
 */
class Geolocation extends BaseGeolocation
{
    /**
     * Stream continuous location updates. Identical to the core builder,
     * plus a chainable ->share(...) to beacon the latest fix to a server.
     *
     * @param  bool  $fineAccuracy  Whether to use high accuracy mode (GPS vs network)
     */
    public function watchPosition(bool $fineAccuracy = false): PendingSharedLocationWatch
    {
        return (new PendingSharedLocationWatch)
            ->fineAccuracy($fineAccuracy);
    }

    /**
     * Share the device's position with a server on an interval — sugar for
     * watchPosition()->background()->share(...). Records durably AND beacons:
     *
     *     $watchId = Geolocation::sharePosition(
     *         url: 'https://api.example.com/live-location',
     *         token: $bearerToken,
     *         interval: 60,
     *         expiresAt: now()->addHours(4),
     *         stopWatchOnExpiry: true,
     *     )->getId();
     *
     *     Geolocation::stopBackgroundWatch($watchId);   // stop sharing + recording
     *
     * @param  string  $url  Absolute endpoint URL (resolve {placeholders} first).
     * @param  string|null  $token  Optional bearer token.
     * @param  int  $interval  Seconds between uploads (min 5, default 60).
     * @param  DateTimeInterface|string|null  $expiresAt  Stop sharing after this moment.
     * @param  bool  $stopWatchOnExpiry  Also stop recording at expiry (buffer kept).
     * @param  bool  $fineAccuracy  High accuracy GPS (default true — beacons usually want it).
     */
    public function sharePosition(
        string $url,
        ?string $token = null,
        int $interval = 60,
        DateTimeInterface|string|null $expiresAt = null,
        bool $stopWatchOnExpiry = false,
        bool $fineAccuracy = true,
    ): PendingSharedLocationWatch {
        return $this->watchPosition($fineAccuracy)
            ->share($url, $token, $interval, $expiresAt, $stopWatchOnExpiry);
    }
}
