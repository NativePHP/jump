<?php

namespace NativePHP\Geolocation;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Native\Mobile\PendingLocationWatch;

/**
 * A location watch that can additionally SHARE the device's latest position
 * with a server on an interval — the Strava-style "live location beacon".
 *
 * Sharing rides the background watch: the same native recorder that buffers
 * fixes durably also POSTs the most recent fix to your endpoint every
 * $interval seconds. Because the share config persists with the watch
 * config, sharing survives app backgrounding, process death and (on
 * Android) device reboot.
 *
 *     Geolocation::watchPosition(fineAccuracy: true)
 *         ->minDistance(5)
 *         ->share(
 *             url: 'https://api.example.com/patrol/sessions/01J.../points',
 *             token: $bearerToken,
 *             interval: 60,
 *             expiresAt: now()->addHours(4)->toIso8601String(),
 *             stopWatchOnExpiry: true,
 *         )
 *         ->getId();
 *
 * Beacon semantics: a failed POST is dropped — the next interval sends a
 * fresher fix, and the durable buffer remains the authoritative history.
 * There is no retry queue by design.
 */
class PendingSharedLocationWatch extends PendingLocationWatch
{
    public const MIN_SHARE_INTERVAL_SECONDS = 5;

    protected ?string $shareUrl = null;

    protected ?string $shareToken = null;

    protected int $shareIntervalSeconds = 60;

    protected ?string $shareExpiresAt = null;

    protected bool $stopWatchOnExpiry = false;

    protected bool $showsBackgroundIndicator = false;

    /**
     * Show iOS's prominent background-location indicator (the blue pill /
     * Dynamic Island glow) while this background watch records. Hidden by
     * default (Life360 parity) — only the standard status-bar arrow shows.
     *
     * Field data (iOS 26.5, Always-authorized session): with the indicator
     * hidden, iOS suspended the recording session in the background and it
     * survived only on ~10s breadcrumb-geofence wakes. Enable this when
     * continuous background delivery matters more than an indicator-free UX.
     *
     * Android: no-op — the foreground-service notification is always shown.
     */
    public function backgroundIndicator(bool $visible = true): static
    {
        $this->showsBackgroundIndicator = $visible;

        return $this;
    }

    /**
     * Share the latest fix with a server every $interval seconds.
     *
     * Sharing implies ->background(): the beacon must outlive the screen.
     *
     * @param  string  $url  Absolute endpoint URL. Resolve any {placeholders}
     *                       (e.g. a session id) BEFORE passing — the native
     *                       layer does no templating.
     * @param  string|null  $token  Optional bearer token sent as Authorization: Bearer {token}.
     * @param  int  $interval  Seconds between uploads (min 5, default 60).
     * @param  DateTimeInterface|string|null  $expiresAt  ISO-8601 moment after which sharing stops natively.
     * @param  bool  $stopWatchOnExpiry  Also stop recording at expiry (the buffer file is kept).
     */
    public function share(
        string $url,
        ?string $token = null,
        int $interval = 60,
        DateTimeInterface|string|null $expiresAt = null,
        bool $stopWatchOnExpiry = false,
    ): static {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_string($scheme)
            || ! in_array(strtolower($scheme), ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
        ) {
            throw new InvalidArgumentException(
                "share() requires an absolute HTTP(S) URL, got [{$url}]."
            );
        }

        if (str_contains($url, '{')) {
            throw new InvalidArgumentException(
                'share() URL still contains an unresolved {placeholder} — resolve it before passing; the native layer does no templating.'
            );
        }

        $this->shareUrl = $url;
        $this->shareToken = $token;
        $this->shareIntervalSeconds = max(self::MIN_SHARE_INTERVAL_SECONDS, $interval);
        $this->shareExpiresAt = $this->normalizeExpiry($expiresAt);
        $this->stopWatchOnExpiry = $stopWatchOnExpiry;

        return $this->background();
    }

    /**
     * Normalize expiry to an unambiguous ISO-8601 timestamp.
     *
     * Invalid expiry must fail closed in PHP. Silently turning a typo into
     * "share forever" is unsafe for a location-sharing feature.
     */
    protected function normalizeExpiry(DateTimeInterface|string|null $expiresAt): ?string
    {
        if ($expiresAt === null) {
            return null;
        }

        if ($expiresAt instanceof DateTimeInterface) {
            return $expiresAt->format(DateTimeInterface::ATOM);
        }

        if (trim($expiresAt) === '') {
            throw new InvalidArgumentException('share() expiresAt must be a valid date-time or null.');
        }

        try {
            return (new DateTimeImmutable($expiresAt))->format(DateTimeInterface::ATOM);
        } catch (Exception) {
            throw new InvalidArgumentException(
                "share() expiresAt must be a valid date-time or null, got [{$expiresAt}]."
            );
        }
    }

    /**
     * Whether this watch shares its position with a server.
     */
    public function isSharing(): bool
    {
        return $this->shareUrl !== null;
    }

    /**
     * Start streaming (and sharing). Auto-called via __destruct for fluent
     * one-liners, exactly like the parent builder.
     *
     * Every background start (sharing or not) goes through this override so
     * options the core builder doesn't know about — showsBackgroundIndicator,
     * the share fields — always reach the native layer. Foreground watches
     * delegate to the parent for its unmount-cleanup handling.
     */
    public function start(): bool
    {
        if (! $this->background && ! $this->isSharing()) {
            return parent::start();
        }

        if ($this->started) {
            return false;
        }

        $this->started = true;

        // Background (and sharing) watches deliberately outlive the screen,
        // so there is never unmount cleanup here. The caller owns the id
        // and stops via Geolocation::stopBackgroundWatch($id).
        if (function_exists('nativephp_call')) {
            nativephp_call('Geolocation.StartBackgroundWatch', json_encode([
                'id' => $this->getId(),
                'event' => $this->eventClass,
                'fineAccuracy' => $this->fineAccuracy,
                'interval' => $this->intervalMs,
                'minDistance' => $this->minDistanceMeters,
                'showsBackgroundIndicator' => $this->showsBackgroundIndicator,
                'shareUrl' => $this->shareUrl,
                'shareToken' => $this->shareToken,
                'shareIntervalMs' => $this->shareIntervalSeconds * 1000,
                'shareExpiresAt' => $this->shareExpiresAt,
                'stopWatchOnExpiry' => $this->stopWatchOnExpiry,
            ]));

            return true;
        }

        return false;
    }
}
