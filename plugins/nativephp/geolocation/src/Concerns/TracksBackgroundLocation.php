<?php

namespace NativePHP\Geolocation\Concerns;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Events\Geolocation\LocationUpdated;
use Native\Mobile\Facades\Geolocation;
use Native\Mobile\PendingLocationWatch;
use NativePHP\Geolocation\Events\AppEnteredForeground;

/**
 * Background-watch client lifecycle for a NativeComponent screen.
 *
 * `use` this trait on any screen that starts, displays, or stops a
 * background location watch. It owns the generic plumbing every app
 * would otherwise rewrite: the durable id + drain-cursor handle in the
 * Cache, re-attaching to a watch that outlived the screen (or the whole
 * process), draining the native buffer, the id-filtered live listener,
 * and per-session state resets.
 *
 * Core has no per-trait lifecycle hooks, so wire the two entry points
 * yourself:
 *
 *     public function mount(): void    { $this->attachBackgroundWatch(); }
 *     public function onResume(): void { $this->syncBackgroundWatch(); }
 *
 * Customize by overriding:
 *  - configureBackgroundWatch()  — accuracy / interval / minDistance
 *  - backgroundWatchCacheKey()   — where the id + cursor live
 *  - onBackgroundFix()           — per-live-fix hook (persist to DB, …)
 *
 * The blade side reads $bgWatchId / $bgLiveUpdates / $bgDrained /
 * $bgLatitude / $bgLongitude / $bgSpeed / $fixes and calls the public methods via
 * `@press`. `trackFixes()` feeds a `<map>` polyline directly.
 */
trait TracksBackgroundLocation
{
    /** The active background watch id, or null when none is attached. */
    public ?string $bgWatchId = null;

    /** Live fixes received while this screen has been up. */
    public int $bgLiveUpdates = 0;

    /** Fixes pulled from the native buffer via incremental drains. */
    public int $bgDrained = 0;

    public ?float $bgLatitude = null;

    public ?float $bgLongitude = null;

    /** Ground speed in m/s (null if the fix has none). */
    public ?float $bgSpeed = null;

    /** The recorded track (drainWatch() fix maps, oldest first). */
    public array $fixes = [];

    /**
     * Re-attach to a background watch that outlived this screen (or the
     * whole process). Native is the source of truth: if it still has a
     * persisted watch, adopt its id, re-seed the cache handle when it's
     * missing or stale, resume live updates, and drain what was buffered
     * while we were gone. Call from mount().
     */
    public function attachBackgroundWatch(): void
    {
        $watch = Geolocation::backgroundWatchStatus();

        if ($watch === null) {
            // Native says nothing is running — drop any stale handle.
            Cache::forget($this->backgroundWatchCacheKey());

            return;
        }

        $this->bgWatchId = $watch['id'];

        // The Cache only remembers our drain cursor; if it lost the
        // handle or points at an older watch, re-seed from native with
        // cursor 0 — otherwise drains silently no-op while the buffer
        // fills.
        $state = Cache::get($this->backgroundWatchCacheKey());
        if (! $state || ($state['id'] ?? null) !== $watch['id']) {
            Cache::put($this->backgroundWatchCacheKey(), ['id' => $watch['id'], 'cursor' => 0]);
        }

        $this->listenToBackgroundWatch();
        $this->drainBackgroundBuffer();
    }

    /**
     * App returned to the foreground with this screen up: collect what
     * the native buffer accumulated while backgrounded. Call from
     * onResume().
     */
    public function syncBackgroundWatch(): void
    {
        if ($this->bgWatchId !== null) {
            $this->drainBackgroundBuffer();
        }
    }

    /**
     * Start a background watch: survives leaving the screen,
     * backgrounding the app, process death, and reboot. Fixes buffer
     * natively and are drained whenever PHP is back.
     */
    public function startBackgroundWatch(): void
    {
        if ($this->bgWatchId !== null) {
            return;
        }

        // Fresh session — a new watch means a new trail.
        $this->resetBackgroundWatchState();

        $this->bgWatchId = $this->configureBackgroundWatch(Geolocation::watchPosition())
            ->background()
            ->getId();

        // The durable handle: everything needed to re-attach later, from
        // this screen or any other.
        Cache::put($this->backgroundWatchCacheKey(), ['id' => $this->bgWatchId, 'cursor' => 0]);

        $this->listenToBackgroundWatch();
    }

    public function stopBackgroundWatch(): void
    {
        if ($this->bgWatchId === null) {
            return;
        }

        // Final drain first so the tail of the stream isn't lost, then
        // stop and delete the buffer.
        $this->drainBackgroundBuffer();
        Geolocation::stopBackgroundWatch($this->bgWatchId, clearBuffer: true);
        Cache::forget($this->backgroundWatchCacheKey());

        $this->bgWatchId = null;
        $this->resetBackgroundWatchState();
    }

    /**
     * Pull everything the native buffer holds past our cursor, and
     * refresh $fixes with the full recorded track. The cursor advances
     * in the Cache so the incremental counter only counts what's new.
     */
    public function drainBackgroundBuffer(): void
    {
        $state = Cache::get($this->backgroundWatchCacheKey());

        if (! $state) {
            return;
        }

        $result = Geolocation::drainWatch($state['id'], $state['cursor']);
        $all = Geolocation::drainWatch($state['id'], 0);

        // The buffer also carries coordinate-less diagnostic markers (e.g.
        // {"marker": "armed", ...} written when native recording re-arms).
        // Only real fixes reach the trail, counters, and persist hook.
        $onlyFixes = fn (array $entries) => array_values(
            array_filter($entries, fn ($fix) => isset($fix['latitude']))
        );

        $newFixes = $onlyFixes($result['fixes']);

        $this->fixes = $onlyFixes($all['fixes']);
        $this->bgDrained += count($newFixes);

        if ($last = end($newFixes)) {
            $this->bgLatitude = $last['latitude'] ?? $this->bgLatitude;
            $this->bgLongitude = $last['longitude'] ?? $this->bgLongitude;
            $this->bgSpeed = isset($last['speed']) ? (float) $last['speed'] : null;
        }

        // Sync-and-trim: when the app confirms it has durably persisted
        // the new fixes (DB row, API call), reclaim their buffer bytes.
        // Offsets rebase to 0 after a trim.
        if ($newFixes !== [] && $this->persistBackgroundFixes($newFixes)) {
            Geolocation::trimWatch($state['id'], $result['cursor']);
            Cache::put($this->backgroundWatchCacheKey(), ['id' => $state['id'], 'cursor' => 0]);

            return;
        }

        Cache::put($this->backgroundWatchCacheKey(), ['id' => $state['id'], 'cursor' => $result['cursor']]);
    }

    /**
     * Track points for a <map> polyline — SIMPLIFIED (Douglas-Peucker) to
     * at most $limit points so the JSON prop stays a sane size on the
     * wire (it re-ships on every publish) while drawing the WHOLE trip at
     * full visual fidelity: straightaways collapse, every corner is
     * preserved exactly. This is how hours-long trips render completely
     * (the Life360 pattern — theirs additionally road-snaps server-side).
     * The tolerance starts at ~5 m and doubles until the track fits.
     * The map element accepts these fix maps directly.
     */
    public function trackFixes(int $limit = 1000): array
    {
        if (count($this->fixes) <= $limit) {
            return $this->fixes;
        }

        $tolerance = 5.0; // meters
        for ($round = 0; $round < 10; $round++) {
            $simplified = $this->simplifyTrack($this->fixes, $tolerance);
            if (count($simplified) <= $limit) {
                return $simplified;
            }
            $tolerance *= 2;
        }

        // Pathological track (noise everywhere): even-stride fallback.
        $count = count($this->fixes);
        $sampled = [];
        $step = ($count - 1) / ($limit - 1);
        for ($i = 0; $i < $limit; $i++) {
            $sampled[] = $this->fixes[(int) round($i * $step)];
        }

        return $sampled;
    }

    /**
     * Iterative Douglas-Peucker over fix maps. $tolerance in meters;
     * coordinates are projected equirectangular at the track's mid
     * latitude (fine at track scale).
     *
     * @param  array<int, array<string, mixed>>  $points
     * @return array<int, array<string, mixed>>
     */
    private function simplifyTrack(array $points, float $tolerance): array
    {
        $n = count($points);
        if ($n < 3) {
            return $points;
        }

        $midLat = deg2rad((float) $points[intdiv($n, 2)]['latitude']);
        $xs = [];
        $ys = [];
        foreach ($points as $p) {
            $xs[] = (float) $p['longitude'] * 111320.0 * cos($midLat);
            $ys[] = (float) $p['latitude'] * 111320.0;
        }

        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $tol2 = $tolerance * $tolerance;
        $stack = [[0, $n - 1]];

        while ($stack !== []) {
            [$first, $last] = array_pop($stack);
            if ($last - $first < 2) {
                continue;
            }

            $ax = $xs[$first];
            $ay = $ys[$first];
            $dx = $xs[$last] - $ax;
            $dy = $ys[$last] - $ay;
            $len2 = $dx * $dx + $dy * $dy;

            $maxDist2 = 0.0;
            $maxIndex = $first;
            for ($i = $first + 1; $i < $last; $i++) {
                // Squared perpendicular distance to the segment.
                if ($len2 > 0.0) {
                    $t = (($xs[$i] - $ax) * $dx + ($ys[$i] - $ay) * $dy) / $len2;
                    $t = max(0.0, min(1.0, $t));
                    $px = $ax + $t * $dx - $xs[$i];
                    $py = $ay + $t * $dy - $ys[$i];
                } else {
                    $px = $xs[$i] - $ax;
                    $py = $ys[$i] - $ay;
                }
                $dist2 = $px * $px + $py * $py;
                if ($dist2 > $maxDist2) {
                    $maxDist2 = $dist2;
                    $maxIndex = $i;
                }
            }

            if ($maxDist2 > $tol2) {
                $keep[$maxIndex] = true;
                $stack[] = [$first, $maxIndex];
                $stack[] = [$maxIndex, $last];
            }
        }

        $result = [];
        foreach ($points as $i => $p) {
            if ($keep[$i]) {
                $result[] = $p;
            }
        }

        return $result;
    }

    /**
     * Where the durable id + cursor handle lives. One ambient watch per
     * app is the native-side reality (the service persists a single
     * config), so a single app-wide key is the right default.
     */
    protected function backgroundWatchCacheKey(): string
    {
        return 'nativephp.geolocation.bg-watch';
    }

    /**
     * Tune the watch before it starts (accuracy, interval, minDistance —
     * and, as the plugin grows, notification/passive options). The trait
     * applies ->background() itself after this returns.
     */
    protected function configureBackgroundWatch(PendingLocationWatch $watch): PendingLocationWatch
    {
        return $watch->fineAccuracy()->interval(3000)->minDistance(1);
    }

    /**
     * Called for every live fix after the default prop updates — persist
     * to the database here without re-implementing the listener.
     */
    protected function onBackgroundFix(object $event): void
    {
        //
    }

    /**
     * Called after the app re-enters the foreground and the durable native
     * buffer has been drained into $fixes.
     */
    protected function onAppEnteredForeground(): void
    {
        //
    }

    /**
     * Sync-and-trim hook. Return TRUE after durably persisting the newly
     * drained fixes (your database, your API) and the trait trims their
     * bytes from the native buffer — the Life360-style "upload then
     * reclaim" loop in one override:
     *
     *     protected function persistBackgroundFixes(array $fixes): bool
     *     {
     *         TrackPoint::insert(...);   // or ship to your server
     *         return true;
     *     }
     *
     * Default FALSE keeps every fix buffered on-device (the demo
     * behavior). Note: once you trim, the buffer no longer holds the full
     * trail — render history from your own store, not trackFixes().
     */
    protected function persistBackgroundFixes(array $fixes): bool
    {
        return false;
    }

    /**
     * Live updates while this screen is up — real-time sugar on top of
     * the buffer. Registered directly (not via the watch builder) so
     * returning to this screen can re-listen to an already-running watch
     * without restarting it.
     */
    private function listenToBackgroundWatch(): void
    {
        $watchId = $this->bgWatchId;

        $this->registerNativeEventListener(AppEnteredForeground::class, function ($event) use ($watchId) {
            if (($event->id ?? null) !== $watchId) {
                return;
            }

            $this->syncBackgroundWatch();
            $this->onAppEnteredForeground();
        });

        $this->registerNativeEventListener(LocationUpdated::class, function ($event) use ($watchId) {
            if (($event->id ?? null) === $watchId) {
                $this->bgLiveUpdates++;
                $this->bgLatitude = $event->latitude;
                $this->bgLongitude = $event->longitude;
                $this->bgSpeed = $event->speed ?? null;

                // Extend the trail live so a map draws each fix as it
                // streams in; the native buffer stays the source of
                // truth — the next drain replaces this with the full
                // recorded track.
                $this->fixes[] = [
                    'latitude' => $event->latitude,
                    'longitude' => $event->longitude,
                ];

                $this->onBackgroundFix($event);
            }
        });
    }

    /**
     * Display state for the CURRENT session. Cleared on stop and again
     * on start, otherwise a restarted watch inherits the previous trail.
     */
    private function resetBackgroundWatchState(): void
    {
        $this->fixes = [];
        $this->bgLiveUpdates = 0;
        $this->bgDrained = 0;
        $this->bgLatitude = null;
        $this->bgLongitude = null;
        $this->bgSpeed = null;
    }
}
