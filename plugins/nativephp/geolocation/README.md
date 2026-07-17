# Geolocation Plugin for NativePHP Mobile

Location services for NativePHP Mobile: one-shot position, foreground
streaming, background recording that survives app kills and reboots, and a
keyless native map component.

> ## ⚠️ Version compatibility — read before upgrading
>
> **v2.x requires `nativephp/mobile` v4.0+** (the native-UI runtime).
>
> This is the **native-UI generation** of the plugin. The `<map>` component,
> the background-recording lifecycle, and the runtime-survival contract it
> depends on all rely on native rendering and process-lifecycle behavior
> introduced in mobile-air v4. **v2 is not compatible with the legacy
> webview runtime (mobile-air v3 and earlier).**
>
> - **On mobile-air v4+** → use **v2.x** of this plugin (this branch).
> - **On a webview / mobile-air v3-or-earlier app** → stay on **v1.x**;
>   it is unaffected by these changes and continues to receive fixes.
>
> Upgrading from v1 is **not** a drop-in bump — it assumes the native-UI
> stack. Don't `composer require` v2 into a webview app.

## Contents

- [Overview](#overview)
- [Installation](#installation)
- [Configuration (.env opt-ins)](#configuration-env-opt-ins)
- [Usage](#usage)
  - [PHP (Livewire/Blade)](#php-livewireblade)
  - [Chainable Callbacks](#chainable-callbacks)
  - [Streaming Location (watchPosition)](#streaming-location-watchposition)
  - [Position Sharing (live-location beacon)](#position-sharing-live-location-beacon)
  - [Background Location (recording that outlives the app)](#background-location-recording-that-outlives-the-app)
    - [Architecture: PHP is the control plane, native is the data plane](#architecture-php-is-the-control-plane-native-is-the-data-plane)
    - [The buffer](#the-buffer)
    - [Reading: drain and cursor](#reading-drain-and-cursor)
    - [Reclaiming space: sync-and-trim](#reclaiming-space-sync-and-trim)
    - [Lifecycle: what survives what](#lifecycle-what-survives-what)
    - [Permissions: the ladder](#permissions-the-ladder)
    - [Debugging: pull the flight recorder](#debugging-pull-the-flight-recorder)
    - [Testing honestly](#testing-honestly)
  - [Native Map](#native-map)
  - [JavaScript (Vue/React/Inertia)](#javascript-vuereactinertia)
- [Events](#events)
  - [`LocationReceived`](#locationreceived)
  - [`LocationUpdated`](#locationupdated)
  - [`PermissionStatusReceived`](#permissionstatusreceived)
  - [`PermissionRequestResult`](#permissionrequestresult)
- [Privacy Considerations](#privacy-considerations)
- [Performance Considerations](#performance-considerations)

## Overview

- **One-shot position** and permission handling (`getCurrentPosition()`).
- **Foreground streaming** (`watchPosition()`) — live fixes to your component.
- **Background recording** (`->background()`) — a native recorder that
  outlives the screen, the app, and the process; fixes buffer on-device and
  your PHP drains them by cursor.
- **`<map>` component** — embedded native maps with zero API keys (MapKit on
  iOS, MapLibre + OpenFreeMap on Android), with markers and a polyline track.

Starting any watch or position request **prompts for permission
automatically** if the user was never asked (both platforms); a denial
dispatches the normal error event instead of prompting again.

## Installation

```bash
composer require nativephp/mobile-geolocation
```

## Configuration (.env opt-ins)

Out of the box the plugin ships **foreground-only** permissions (`INTERNET` +
`ACCESS_FINE/COARSE_LOCATION`) — enough for `getCurrentPosition()` and a plain
`watchPosition()`, and nothing that triggers Google Play's foreground-service /
background-location declarations. The heavier tiers are opt-in per app:

```dotenv
# Foreground-location service — keeps a watch recording while the app is open
# OR backgrounded (not after a kill). Adds FOREGROUND_SERVICE(_LOCATION) +
# POST_NOTIFICATIONS + the LocationWatchService on Android, and the iOS
# `location` background mode.
NATIVEPHP_GEOLOCATION_FOREGROUND_SERVICE=true

# Killed-app + reboot survival — the ->background() recorder. Implies the
# foreground service and additionally adds ACCESS_BACKGROUND_LOCATION +
# RECEIVE_BOOT_COMPLETED + the boot receiver on Android, and the iOS
# NSLocationAlwaysAndWhenInUseUsageDescription string.
NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION=true
```

A build-time hook inserts exactly these manifest / Info.plist entries when a
flag is set — and strips them when unset — so what you ship matches what you
enabled. Rebuild after changing a flag. To customize further (e.g. the usage
strings), publish the config:

```bash
php artisan vendor:publish --tag=nativephp-geolocation-config
```

Set these **before** shipping background features: `->background()` /
`->share(...)` won't record with the flags off. See
[Background Location](#background-location-recording-that-outlives-the-app)
for the lifecycle and store-review details.

## Usage

### PHP (Livewire/Blade)

```php
use Native\Mobile\Facades\Geolocation;

// Get location using network positioning (faster, less accurate)
Geolocation::getCurrentPosition();

// Get location using GPS (slower, more accurate)
Geolocation::getCurrentPosition(true);

// Check permission status
Geolocation::checkPermissions();

// Request permissions
Geolocation::requestPermissions();
```

### Chainable Callbacks

Instead of declaring an `#[On]` listener, chain a callback for the result
directly onto the call. The callback receives the event object and runs on your
live component, so `$this` works just like it does in a listener. Each action has
its own named handler:

```php
use Native\Mobile\Facades\Geolocation;

Geolocation::getCurrentPosition(true)
    ->locationReceived(function ($event) {
        if ($event->success) {
            $this->latitude = $event->latitude;
            $this->longitude = $event->longitude;
        } else {
            $this->error = $event->error;
        }
    });

Geolocation::checkPermissions()
    ->permissionStatusReceived(fn ($event) => $this->status = $event->location);

Geolocation::requestPermissions()
    ->permissionRequestResult(fn ($event) => $this->status = $event->location);
```

If you set a custom event via `->event(MyEvent::class)`, register its callback with
the generic escape hatch: `->on(MyEvent::class, fn ($event) => ...)`.

**Closure rules:**

- A regular closure is bound to your live component — use `$this` freely. It lives
  in memory only, so it won't survive the OS killing the app while waiting for a fix.
- A `static` closure can't touch `$this`, but is serialized to durable storage and
  will still fire if the app process is restarted before the result arrives.

Callbacks are one-shot and coexist with `#[On]` listeners — both fire.

### Streaming Location (watchPosition)

For live tracking — a moving map marker, a delivery ETA, a run tracker — use
`watchPosition()` instead of polling `getCurrentPosition()`. Each native GPS fix
dispatches a `LocationUpdated` event; the `->locationUpdated()` handler is
**persistent** (fires for every update, unlike the one-shot callbacks above) and
runs on your live component:

```php
use Native\Mobile\Facades\Geolocation;

public string $watchId = '';

public function mount(): void
{
    $this->watchId = Geolocation::watchPosition(fineAccuracy: true)
        ->interval(2000)      // target ms between updates (Android pacing)
        ->minDistance(5)      // meters moved before another update (both platforms)
        ->locationUpdated(function ($event) {
            if ($event->success) {
                $this->latitude = $event->latitude;
                $this->longitude = $event->longitude;
                $this->speed = $event->speed;        // m/s, null if unknown
                $this->heading = $event->heading;    // degrees, null if unknown
            } else {
                $this->error = $event->error;
            }
        })
        ->getId();
}

public function stopTracking(): void
{
    Geolocation::clearWatch($this->watchId);
}
```

- The watch stops **automatically when the component unmounts** — no cleanup
  needed for screen-scoped tracking. Use `clearWatch()` to stop earlier.
- Multiple concurrent watches are supported; each handler only receives its own
  watch's updates (correlated by id).
- A plain watch is **foreground-only**: the OS suspends delivery when the app
  is backgrounded. For recording that survives backgrounding, app kills, and
  reboots, chain `->background()` — see below.
- Prefer `->minDistance()` over a tight `->interval()` to save battery — on iOS
  it's the only throttle (CoreLocation is event-driven; `interval` is ignored).

### Position Sharing (live-location beacon)

A background watch can additionally **share the device's latest position with
your server** on an interval — the Strava-style "live location" feature. The
native layer POSTs the most recent fix (one small JSON request per interval,
bearer-authenticated) with no PHP involvement, so the beacon keeps beating
while the app is backgrounded and re-arms after process death and reboot,
exactly like the recording it rides on.

```php
// Chainable on any watch (share implies ->background()):
$watchId = Geolocation::watchPosition(fineAccuracy: true)
    ->minDistance(5)
    ->share(url: $endpoint, token: $bearer, interval: 60, expiresAt: now()->addHours(4))
    ->getId();

// Or the sugar entry point:
$watchId = Geolocation::sharePosition(url: $endpoint, token: $bearer, interval: 60)->getId();
```

Beacon semantics: failed POSTs are dropped (no retry queue — the durable
buffer below already holds every fix), the first fix ships immediately, expiry
is enforced by the native recorder (on iOS, before the next delivered fix), and `backgroundWatchStatus()` reports
`shareActive` / `lastShareAt` / `lastShareStatus`. **Full reference:
[SHARE_README.md](SHARE_README.md).**

### Background Location (recording that outlives the app)

> **Native-UI (PHP) only.** The `TracksBackgroundLocation` trait and the
> whole drain / trim / re-attach lifecycle are built for PHP screens
> extending `NativeComponent` — the Livewire-style native-UI API. They work
> there, but have **no webview / JS (Vue/React/Inertia) equivalent**: those
> apps get the one-shot position, foreground `watchPosition()`, and
> permission calls, not the background recorder or the trait.

> **Requires opt-in.** Everything in this section is **off by default** — set
> `NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION=true` in `.env` and rebuild, or
> the recorder won't run and the manifest won't carry the background
> permissions. See [Configuration (.env opt-ins)](#configuration-env-opt-ins).

`->background()` turns the watch into a native background recorder: a
foreground service on Android, a background `CLLocationManager` on iOS. It
deliberately does **not** stop when the screen unmounts — fixes are appended
to an on-device buffer (the source of truth) and your PHP drains them by
cursor whenever it's back. Live `LocationUpdated` events still fire while the
app is foregrounded.

The easiest integration is the screen trait, which owns the whole client
lifecycle (durable id+cursor handle, re-attach on mount, drain on resume,
live listener, trail for `<map>`):

```php
use NativePHP\Geolocation\Concerns\TracksBackgroundLocation;

class RunTracker extends NativeComponent
{
    use TracksBackgroundLocation;

    public function mount(): void    { $this->attachBackgroundWatch(); }
    public function onResume(): void { $this->syncBackgroundWatch(); }

    // Optional: durable-store + reclaim loop (Life360-style sync-and-trim)
    protected function persistBackgroundFixes(array $fixes): bool
    {
        TrackPoint::insert(...);
        return true;   // trait trims the uploaded bytes from the buffer
    }
}
```

Under the hood: `Geolocation::backgroundWatchStatus()`,
`drainWatch($id, $cursor)`, `trimWatch($id, $upTo)`,
`stopBackgroundWatch($id, clearBuffer: true)`.

> **Read the rest of this section before shipping background tracking.** It
> covers where the data lives, what survives an app kill, the permission
> ladder, and the store-review implications — the parts that bite in
> production, not the demo.

#### Architecture: PHP is the control plane, native is the data plane

Your Laravel runtime lives with the app UI — when the app is backgrounded or
killed, PHP is not running. A robust tracker therefore never puts PHP in the
hot path:

- A **native recorder** (Android foreground service / iOS background
  `CLLocationManager`) collects fixes autonomously.
- Every fix is appended to an **on-device buffer** — the source of truth.
- **Live events** (`LocationUpdated`) are best-effort real-time sugar,
  delivered only while the app has visible UI.
- When PHP is back (screen mount, app resume), it **drains** the buffer by
  cursor and catches up on everything it missed.

```
start ──▶ native recorder ──▶ JSONL buffer  (always, app dead or alive)
                       └────▶ live events   (foreground only)
PHP mount/resume ──▶ drainWatch(id, cursor) ──▶ your DB / UI
                     └─ optional: trimWatch() reclaims uploaded bytes
```

#### The buffer

One append-only JSONL file per watch id. One fix per line, same keys as the
live event: `latitude`, `longitude`, `accuracy`, `speed`, `heading`,
`timestamp` (ms epoch), `provider`, `id`.

| | Android | iOS |
|---|---|---|
| Location | `noBackupFilesDir/nativephp_location/<id>.jsonl` | `Application Support/nativephp_location/<id>.jsonl` |
| Written by | foreground service, private worker thread | `CLLocationManager` delegate |
| OS eviction | never (not a cache dir) | never (not Caches/tmp) |
| Backups | excluded (`noBackupFilesDir`) | excluded (`isExcludedFromBackup`) |
| Encryption at rest | device FBE | `FileProtectionType.none`¹ |
| Survives | reboot, app update, process death | reboot, app update, process death |
| Deleted by | `stopBackgroundWatch(clearBuffer: true)`, `trimWatch()`, app uninstall / Clear Storage | same |

¹ Deliberate: a significant-change relaunch can arrive after a device reboot
but **before first unlock**, when protected files are unwritable — recording
must not fail in that window. The file is still app-sandboxed and excluded
from backups.

Budget: ~150 bytes/fix → a 3-second fine-accuracy watch writes roughly
180 KB/hour. There is no rotation or size cap — retention is your job (see
[sync-and-trim](#reclaiming-space-sync-and-trim)).

Durability model: one fix, one append, no batching. A crash loses at most
the fix mid-write. Appends are effectively atomic at these sizes; the reader
skips malformed lines defensively.

**Marker lines (raw `drainWatch()` consumers must know this).** The buffer
is also a **flight recorder**: iOS interleaves coordinate-less diagnostic
marker lines between fixes. `drainWatch()` returns them as-is — **filter on
the presence of `latitude`** before treating entries as fixes (the
`TracksBackgroundLocation` trait does this for you).

| marker | meaning |
|---|---|
| `armed` | recorder (re)started — carries `authorization`, `appState`, `backgroundMode`. NOTE: the `authorization` here can be a stale cold-launch read; trust the `authorization` marker that follows |
| `authorization` | authoritative status from the CoreLocation delegate callback |
| `entered_background` / `became_active` / `will_terminate` | app lifecycle, with `lowPowerMode` + `backgroundRefresh` snapshots. `will_terminate` only fires on a user swipe-kill — its presence distinguishes force-quit from a system kill |
| `breadcrumb_exit` | the ~100 m geofence woke the app (`appState=background` = headless relaunch) |
| `breadcrumb_registered` / `breadcrumb_state` / `breadcrumb_failed` | region-arming forensics: `locationd` confirmed the crumb, determined inside/outside state, or rejected it. A kill BEFORE `breadcrumb_state: inside` explains a missing resurrection |

#### Reading: drain and cursor

Drains are **non-destructive byte-offset reads** — a peek, never a pop:

```php
$result = Geolocation::drainWatch($watchId, $cursor);
// $result['fixes']  → everything after $cursor (oldest first)
// $result['cursor'] → new offset; persist it (Cache/DB) for the next drain
```

- Cursor `0` re-reads the entire buffer.
- The cursor is **owned by PHP** — advance it only after you've handled the
  fixes; a crash mid-ingest means the next drain re-reads the same tail.
  Nothing is ever lost by reading.
- Out-of-range cursors (buffer cleared/trimmed underneath you) reset to 0.
- One drain reads cursor→EOF in a single payload; keep sessions to
  thousands, not millions, of fixes or trim as you go.

#### Reclaiming space: sync-and-trim

`trimWatch()` discards bytes **before** an offset — the reclaim half of the
upload cycle. Only trim what you have durably persisted:

```php
$result = Geolocation::drainWatch($id, $cursor);
TrackPoint::insert(...$result['fixes']);          // or POST to your API
Geolocation::trimWatch($id, $result['cursor']);   // reclaim those bytes
$cursor = 0;                                      // offsets rebase after trim
```

With the trait, returning `true` from `persistBackgroundFixes()` (shown
above) does the trim + cursor rebase for you. **After you start trimming,
the buffer no longer holds the full trail — render history from your own
store, not `trackFixes()`.**

For **eager sync while backgrounded** (uploading without the user opening
the app), pair this with the `nativephp/mobile-background-tasks` plugin: a
periodic task boots the worker runtime, drains, uploads, trims. Session
segmentation (a new watch id per trip) is `stopBackgroundWatch()` + a fresh
`->background()` start — fixes carry timestamps, so stationary-detection
policy belongs in your app code.

#### Lifecycle: what survives what

| Event | Android | iOS |
|---|---|---|
| Leave the screen | keeps recording (watch is app-scoped, not screen-scoped) | same |
| Background the app | foreground service records continuously (persistent notification) | continuous updates; only the standard status-bar location arrow (the prominent background indicator pill is deliberately off — Always doesn't require it) |
| Process killed by OS | service restarts (`START_STICKY`), resumes from persisted config | significant-change relaunch re-arms the recorder |
| Force-quit by user | service restarts | relaunches via the wake trio (below). Wakes are coarse and asynchronous — expect a gap of minutes / hundreds of meters after the kill before recording resumes |
| Device reboot | boot receiver re-arms | next significant change or app launch re-arms |
| App reopened | `backgroundWatchStatus()` → re-attach → drain | same |

Re-attach is entirely handled by the trait's `mount()` / `onResume()` hooks.
Native is the source of truth for "is a watch running" —
`Geolocation::backgroundWatchStatus()` reports the persisted watch (id,
config, buffer size) even to a freshly booted PHP runtime that has never
heard of it. On iOS it also reports the delivery gates so your UI can warn
instead of going silently dark: `authorization` (`always`/`whenInUse`/…),
`backgroundMode` (the built app actually declares the location background
mode), `lowPowerMode`, and `backgroundRefresh`
(`available`/`denied`/`restricted`).

**Force-quit resurrection (verified on-device).** Three independent wake
sources are armed, all of which relaunch a terminated app per Apple's
Location Awareness guide ("the only way to have your app relaunched
automatically is to use region monitoring or the significant-change location
service"):

1. **Breadcrumb geofence** — a ~100 m exit-region planted immediately at
   start (from the cached last-known location, with an explicit
   region-state request so `locationd` establishes the inside-state an exit
   fires from) and leapfrogged forward as fixes arrive. Regions live in
   `locationd`, outside the process. Tightest wake: in a road test, a
   force-quit app was relaunched headless **99 seconds** after the kill and
   recorded the remaining 11 km continuously. Field caveat under
   investigation: kills within ~30 s of starting (before the region's
   inside-state is established) have not resurrected — give a fresh watch a
   minute before force-quitting, or background instead of killing.
2. **Significant-change** — ~500 m / cell-handoff granularity at a
   several-minute cadence. Don't judge it with a short test: a sub-5-minute
   window can look like "no relaunch" purely because no event was due.
3. **Visits** — arrive/leave detection, coarsest but cheapest.

The relaunched process re-arms through the plugin init (`armed` marker), and
the pre-wake gap renders as a straight polyline segment — split your trail
on timestamp gaps if you want to display it honestly.

#### Permissions: the ladder

Starting a watch prompts automatically (see the top of this README), but the
authorization *level* determines what survives:

- **While Using** — records while the app is open or backgrounded. The
  moment the process dies, recording stops and the OS will not relaunch you.
  Sufficient for workout-style tracking where the user keeps the session
  alive.
- **Always** (iOS) / **Allow all the time** (Android) — unlocks killed-app
  recording: the wake trio on iOS, boot-receiver re-arm on Android. Both
  platforms force a two-step flow (While-Using first, then the upgrade); on
  Android 11+ the upgrade is a Settings redirect, not a dialog. The plugin
  starts recording under While-Using and escalates — but your UX should
  surface the difference (a "recording stops if you close the app" warning
  beats a silent gap).

**Store review reality:** opting in (`NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION`)
declares `ACCESS_BACKGROUND_LOCATION` + a location foreground service (Play
requires a declaration form/video) and the iOS `location` background mode (App
Review will ask why). Budget for it — and leave the flag **off** for apps that
only read a foreground position, or Play will flag those declarations on a
build that never uses them.

#### Debugging: pull the flight recorder

The buffer answers lifecycle questions that logs can't — pull it from a
connected device **without opening the app** (the app's state stays
untouched):

```bash
# iOS (debug builds)
xcrun devicectl device copy from --device <udid> \
  --domain-type appDataContainer --domain-identifier <bundle-id> \
  --source "Library/Application Support/nativephp_location" --destination ./out

# Android
adb shell run-as <package> sh -c 'cat no_backup/nativephp_location/*.jsonl'
```

Read it as a timeline: fixes + markers, ordered. Gaps that end with an
`armed`/`breadcrumb_exit` marker in `appState=background` are wake latency
(normal); gaps with no markers at all mean the process was never woken —
check authorization and the gates above before suspecting code.

#### Testing honestly

The **simulator/emulator validates logic, not policy**: simulated location
is a firehose, processes aren't really terminated, permission gates are
soft, and there are no OEM task killers. Killed-app recording, relaunch
behavior, Always-permission flows, and battery impact are only real on a
**physical device**. City Run on a simulator proving your pipeline ≠ a phone
in a pocket proving your product.

### Native Map

The `<map>` and `<map-marker>` elements.

> **Native-UI (PHP) only.** `<map>` / `<map-marker>` are natively rendered
> elements — they exist inside PHP native-UI screens (Livewire-style
> `NativeComponent`), not in webview / JS apps. In a webview app, use a
> JavaScript map library instead.

Embedded native maps with **no API keys on either platform**: SwiftUI MapKit
on iOS, MapLibre GL + [OpenFreeMap](https://openfreemap.org) vector tiles on
Android.

```blade
<map class="w-full h-72 rounded-2xl"
     :lat="$lat" :lng="$lng" :zoom="$zoom"
     :follow="$following"
     :polyline="$this->trackFixes()"
     polyline-color="#FC4C02" polyline-width="4"
     show-user-location="true">

    <map-marker :lat="$lat" :lng="$lng" title="Current" color="#FC4C02"
                :ios-icon="\App\Icons\Ios::LocationFill"
                :android-icon="\App\Icons\Android::MyLocation"
                @press="recenter"/>
</map>
```

**`<map>` props**

| Prop | Default | Notes |
|---|---|---|
| `lat` / `lng` | — | camera center; omit to auto-fit the polyline |
| `zoom` | `13` | web-mercator scale (~10 city → ~18 street) |
| `follow` | `true` | camera tracks lat/lng changes. `false` = position it once, then the user owns pan/zoom (explicit `zoom` changes still apply, pivoting at the user's center) |
| `style` | `standard` | `standard` / `satellite` / `hybrid` — fully honored on iOS; Android's keyless tiles have no satellite imagery, all values render the standard map |
| `interactive` | `true` | pan/pinch gestures |
| `show-user-location` | `false` | OS user-location dot (needs location permission; silently skipped without) |
| `polyline` | — | track to draw: pass `drainWatch()` fix maps or `[[lat, lng], ...]` pairs directly. Points are rounded and re-shipped per publish — keep under ~1000. The trait's `trackFixes()` Douglas-Peucker-simplifies the full trip to fit (straightaways collapse, corners survive), so hours-long recordings render completely |
| `polyline-color` / `polyline-width` | `#FC4C02` / `4` | |

**`<map-marker>`** children are data, not views: `lat`, `lng`, `title`,
`color` (default dot), optional platform icon triple (`icon` shared name,
`:ios-icon` SF Symbol, `:android-icon` Material — enum or string, same
resolution as buttons/tabs), and `@press`. Android rasterizes the Material
glyph from the bundled ligature font, so any icon name that works elsewhere
works on a marker.

The map defaults to 280 units tall, so a bare `<map class="w-full"/>` is
visible without an explicit height.

### JavaScript (Vue/React/Inertia)

The one-shot position and permission calls are available over the webview
bridge. **Background recording (the `TracksBackgroundLocation` trait, buffer
drain/trim) and the `<map>` / `<map-marker>` component are native-UI only —
they have no webview/JS equivalent.** Reach for PHP native-UI (Livewire-style)
screens when you need those.

```js
import { Geolocation, On, Off, Events } from '#nativephp';

// Get location using network positioning
await Geolocation.getCurrentPosition();

// Get location using GPS (high accuracy)
await Geolocation.getCurrentPosition()
    .fineAccuracy(true);

// With identifier for tracking
await Geolocation.getCurrentPosition()
    .fineAccuracy(true)
    .id('current-loc');

// Check permissions
await Geolocation.checkPermissions();

// Request permissions
await Geolocation.requestPermissions();
```

## Events

### `LocationReceived`

Fired when location data is requested (success or failure).

**Event Parameters:**
- `bool $success` - Whether location was successfully retrieved
- `float $latitude` - Latitude coordinate (when successful)
- `float $longitude` - Longitude coordinate (when successful)
- `float $accuracy` - Accuracy in meters (when successful)
- `int $timestamp` - Unix timestamp of location fix
- `string $provider` - Location provider used (GPS, network, etc.)
- `string $error` - Error message (when unsuccessful)

#### PHP

Use the `#[On]` attribute — the Livewire-free native-UI listener. (The
legacy `#[OnNative]` attribute extends Livewire's `BaseOn` and requires
Livewire; it exists only for v1 / webview-Livewire apps. In native-UI, use
`#[On]` or the chainable callbacks shown earlier.)

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Geolocation\LocationReceived;

#[On(LocationReceived::class)]
public function handleLocationReceived(
    $success = null,
    $latitude = null,
    $longitude = null,
    $accuracy = null,
    $timestamp = null,
    $provider = null,
    $error = null
) {
    if ($success) {
        // Use location data
    }
}
```

#### Vue

```js
import { On, Off, Events } from '#nativephp';
import { ref, onMounted, onUnmounted } from 'vue';

const location = ref({ latitude: null, longitude: null });
const error = ref('');

const handleLocationReceived = (payload) => {
    if (payload.success) {
        location.value = {
            latitude: payload.latitude,
            longitude: payload.longitude
        };
    } else {
        error.value = payload.error;
    }
};

onMounted(() => {
    On(Events.Geolocation.LocationReceived, handleLocationReceived);
});

onUnmounted(() => {
    Off(Events.Geolocation.LocationReceived, handleLocationReceived);
});
```

### `LocationUpdated`

Fired repeatedly — once per fix — while a `watchPosition()` stream is active.

**Event Parameters:**
- `bool $success` - Whether this update carries a fix (false = watch error)
- `float $latitude` / `float $longitude` - Coordinates
- `float $accuracy` - Accuracy in meters
- `?float $speed` - Ground speed in m/s (null if the fix has none)
- `?float $heading` - Course over ground in degrees (null if the fix has none)
- `int $timestamp` - Unix timestamp (ms) of the fix
- `string $provider` - Location provider
- `?string $error` - Error message (when unsuccessful)
- `string $id` - The watch id (correlate concurrent watches)

Prefer the persistent `->locationUpdated()` handler; an `#[On(LocationUpdated::class)]`
listener also works, but receives updates from **every** active watch — check `$id`.

### `PermissionStatusReceived`

Fired when permission status is checked.

**Permission Values:**
- `'granted'` - Permission is granted
- `'denied'` - Permission is denied
- `'not_determined'` - Permission not yet requested

### `PermissionRequestResult`

Fired when a permission request completes.

**Special Values:**
- `'permanently_denied'` - User has permanently denied permission

## Privacy Considerations

- **Explain why** you need location access before requesting
- **Request at the right time** - when the feature is actually needed
- **Respect denials** - provide alternative functionality when possible
- **Use appropriate accuracy** - don't request fine location if coarse is sufficient
- **Limit frequency** - don't request location updates constantly

## Performance Considerations

- **Battery Usage** - GPS uses more battery than network location
- **Time to Fix** - GPS takes longer for initial position
- **Indoor Accuracy** - GPS may not work well indoors
- **Caching** - Consider caching recent locations for better UX
