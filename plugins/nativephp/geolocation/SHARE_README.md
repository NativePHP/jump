# Position Sharing — live-location beacon

`share()` turns a background location watch into a **Strava-style live-location beacon**: while the watch records its durable on-device trail, the native layer also POSTs the device's **latest fix** to your server on an interval. Admins see "where is this user right now" without the app shipping the whole route — and without any PHP running.

Sharing rides the existing background watch. Same foreground service (Android) / background CLLocationManager (iOS), same durable JSONL buffer, same reboot/process-death survival: because the share config persists alongside the watch config, **the beacon re-arms after a sticky-service restart and after device reboot** on Android, and after any relaunch on iOS.

## API

### Chainable on the watch builder

```php
use Native\Mobile\Facades\Geolocation;

$watchId = Geolocation::watchPosition(fineAccuracy: true)
    ->minDistance(5)
    ->share(
        url: 'https://api.example.com/patrol/sessions/01JZX.../points',
        token: $bearerToken,
        interval: 60,
        expiresAt: now()->addHours(4),
        stopWatchOnExpiry: true,
    )
    ->getId();
```

`share()` implies `->background()` — a beacon must outlive the screen. Store the id durably; stop with `Geolocation::stopBackgroundWatch($watchId)`.

### Sugar entry point

```php
$watchId = Geolocation::sharePosition(
    url: 'https://api.example.com/live-location',
    token: $bearerToken,
    interval: 60,                       // seconds
    expiresAt: '2026-07-13T22:00:00Z',  // or a DateTimeInterface, or null
    stopWatchOnExpiry: false,
    fineAccuracy: true,
)->getId();
```

`sharePosition(...)` ≡ `watchPosition($fineAccuracy)->background()->share(...)`. It returns the same builder, so everything chainable on a watch (`->id()`, `->minDistance()`, `->interval()`, `->locationUpdated()`, `->event()`) works here too.

## Options

| Option | Type | Default | Notes |
|---|---|---|---|
| `url` | `string` (required) | — | Absolute HTTP(S) URL. **Resolve any `{placeholders}` before passing** — unsupported schemes and an unresolved `{` throw `InvalidArgumentException`. |
| `token` | `?string` | `null` | Sent as `Authorization: Bearer {token}` when present. |
| `interval` | `int` seconds | `60` | Time between uploads. Clamped to a minimum of **5**. On iOS uploads are throttled per-fix (CoreLocation is the clock), so the effective cadence is `max(interval, time-to-next-fix)`. |
| `expiresAt` | `DateTimeInterface\|string\|null` | `null` | Moment after which sharing stops **natively** — no PHP needed. Strings are parsed and normalized to ISO-8601; invalid values throw `InvalidArgumentException` instead of accidentally sharing forever. `null` = never expires. |
| `stopWatchOnExpiry` | `bool` | `false` | `false`: at expiry only the beacon stops; recording continues. `true`: the whole watch stops at expiry — recording ends and the persisted config clears, but **the buffer file is kept (frozen)** for the app to drain on next open. |

### Background indicator (iOS)

```php
$watchId = Geolocation::watchPosition(fineAccuracy: true)
    ->minDistance(5)
    ->backgroundIndicator(true)   // show iOS's blue background-location pill
    ->share(...)
    ->getId();
```

| Option | Type | Default | Notes |
|---|---|---|---|
| `backgroundIndicator` | `bool` | `false` | Controls `CLLocationManager.showsBackgroundLocationIndicator` for the background watch. Default `false` = no prominent blue pill / Dynamic Island glow while recording (Life360 parity) — only the standard status-bar location arrow shows. **Field data (iOS 26.5, Always-authorized session): with the indicator hidden, iOS suspended the recording session in the background**, leaving only ~10 s breadcrumb-geofence wakes per ~100 m — roughly two-thirds of a 42-minute ride's fixes were never delivered. Pass `true` when continuous background delivery matters more than an indicator-free UX. The value persists with the watch config, so relaunches resume with the same behavior. Android: accepted and ignored — the foreground-service notification is always visible there. |

## HTTP contract

One request per interval, containing the single latest fix:

```
POST {url}
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}        (when token given)
Timeout: 15s

{"points": [{
    "latitude": 45.5017,
    "longitude": -73.5673,
    "accuracy": 8.2,
    "speed": 3.1,          // m/s — omitted when the fix has none
    "heading": 121.5,      // degrees — omitted when the fix has none
    "altitude": 231.0,     // meters — omitted when the fix has none
    "timestamp": "2026-07-13T14:03:22Z"   // ISO-8601 UTC, the FIX time (may be older than send time)
}]}
```

The response body is ignored; only the status code is recorded (see Status below). Any 2xx/4xx/5xx ends that beacon attempt — there is no retry.

## Semantics you should design around

- **Beacon, not a pipeline.** A failed POST (offline, DNS, timeout, server error) is **dropped**; the next interval sends a fresher fix. There is deliberately no retry queue — the durable JSONL buffer already holds every fix, so history is never lost, and your app can upload the full trail later via the drain → upload → trim cycle.
- **First fix ships immediately** after the watch starts (not one interval later), so the server learns the position within seconds.
- **Stale beats silent**: if the device hasn't moved (minDistance not crossed) the latest known fix is re-sent on Android's timer; on iOS no new fix means no send — the payload's `timestamp` always tells your server how fresh the data is.
- **Survival**: Android — `START_STICKY` restart and the `BOOT_COMPLETED` receiver re-arm recording *and* sharing from the persisted config. iOS — any relaunch (user reopen, breadcrumb-geofence wake, significant-change) re-arms both via `geolocationPluginInit`.
- **Expiry is native.** Android checks expiry on its native share timer. iOS has no reliable background timer, so it enforces expiry before the next delivered location fix or relaunch; it will never send a fix after detecting expiry. An expired share records `lastShareStatus = -1`.

## Observing share state

`Geolocation::backgroundWatchStatus()` gains share fields:

```php
[
    'active' => true,
    'id' => 'patrol-trail',
    // ... existing watch fields ...
    'shareActive' => true,
    'shareIntervalMs' => 60000,
    'shareExpiresAt' => '2026-07-13T22:00:00Z',  // absent when no expiry
    'lastShareAt' => '2026-07-13T14:03:22Z',     // absent before the first attempt
    'lastShareStatus' => 200,                    // HTTP code; 0 = network failure; -1 = expired
]
```

No events are emitted per share in v1 — refresh the status from the
`AppEnteredForeground` handler below or a `#[Poll]` tick to render
"Last shared 14:03 ✓".

### Foreground event

While a background watch is active, the plugin emits `AppEnteredForeground`
when an already-running app returns from the background. The watcher itself
never paused: this event announces that the UI/PHP runtime is available again,
so it is a good time to drain buffered fixes, refresh share status, redraw a
route, or resume other foreground-only work.

```php
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Facades\Geolocation;
use NativePHP\Geolocation\Events\AppEnteredForeground;

#[OnNative(AppEnteredForeground::class)]
public function appEnteredForeground(string $id, int $timestamp): void
{
    if ($id !== $this->watchId) {
        return;
    }

    $trail = Geolocation::drainWatch($id, 0);
    $this->fixes = $trail['fixes'];
}
```

Components using `TracksBackgroundLocation` do not need to register the event
themselves. The trait filters by watch id, drains the durable buffer into
`$fixes`, and then calls an optional hook:

```php
protected function onAppEnteredForeground(): void
{
    $this->redrawRoute($this->trackFixes());
}
```

This is a warm-resume signal, not a cold-start guarantee. Continue calling
`attachBackgroundWatch()` from `mount()` so a relaunched process also adopts
the native watch and drains its buffer.

If `stopWatchOnExpiry` stops the whole watch, status retains a terminal result until a new watch starts or the app explicitly stops/clears it:

```php
[
    'active' => false,
    'id' => 'patrol-trail',
    'shareActive' => false,
    'lastShareStatus' => -1,
]
```

## Platform notes

- **Android**: uploads run on a dedicated single-thread executor (never the location worker, never main), via `HttpURLConnection` — no new dependencies; `INTERNET` permission is already in the plugin manifest. The share timer ticks on the watch's worker `HandlerThread`.
- **iOS**: no background timers exist, so uploads are throttled per-fix inside `didUpdateLocations` — CoreLocation is the clock. `URLSession` is independent of the Laravel bridge, so sharing is **safe in headless relaunched processes** (where event emission is not).
- **Security**: the bearer token is persisted in plain SharedPreferences / UserDefaults, like the rest of the watch config. Prefer short-lived, narrowly-scoped tokens. Keychain / EncryptedSharedPreferences storage is a tracked follow-up.

## Stopping

```php
Geolocation::stopBackgroundWatch($watchId);                     // stop sharing + recording, keep buffer
Geolocation::stopBackgroundWatch($watchId, clearBuffer: true);  // ... and delete the buffered trail
```
