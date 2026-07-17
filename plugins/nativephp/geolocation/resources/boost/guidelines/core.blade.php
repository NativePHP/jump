## nativephp/geolocation

GPS location and permission handling for NativePHP Mobile applications.

### PHP Usage (Livewire/Blade)

@verbatim
<code-snippet name="Using Geolocation Facade" lang="php">
use Native\Mobile\Facades\Geolocation;

// Get location using network positioning (faster, less accurate)
Geolocation::getCurrentPosition();

// Get location using GPS (slower, more accurate)
Geolocation::getCurrentPosition(true);

// Check permission status
Geolocation::checkPermissions();

// Request permissions
Geolocation::requestPermissions();
</code-snippet>
@endverbatim

### Chainable Callbacks (preferred for one-shot results)

Chain a callback for the result instead of declaring an `#[OnNative]` listener.
The callback receives the event object and is bound to the live component (`$this` works).
For a custom event set via `->event(MyEvent::class)`, use `->on(MyEvent::class, $callback)`.

@verbatim
<code-snippet name="Geolocation Callbacks" lang="php">
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
</code-snippet>
@endverbatim

- Regular closures may use `$this` (live component) but are in-memory only.
- `static` closures cannot use `$this` but survive the OS killing the app before the result arrives.
- Callbacks are one-shot and coexist with `#[OnNative]` listeners.

### Streaming Location (watchPosition)

For live tracking use `watchPosition()` — each GPS fix dispatches `LocationUpdated`
and the `->locationUpdated()` handler is PERSISTENT (fires per update, unlike the
one-shot callbacks above). The watch auto-stops when the component unmounts;
stop earlier with `Geolocation::clearWatch($id)`. Foreground-only.

@verbatim
<code-snippet name="Streaming location" lang="php">
use Native\Mobile\Facades\Geolocation;

// In mount() — store the id to stop manually later
$this->watchId = Geolocation::watchPosition(fineAccuracy: true)
    ->interval(2000)     // target ms between updates (Android; iOS ignores)
    ->minDistance(5)     // meters moved before another update (both platforms)
    ->locationUpdated(function ($event) {
        $this->lat = $event->latitude;
        $this->lng = $event->longitude;
        // also: $event->speed (m/s), $event->heading (degrees), $event->accuracy
    })
    ->getId();

Geolocation::clearWatch($this->watchId);   // manual stop
</code-snippet>
@endverbatim

- Multiple concurrent watches supported; handlers only receive their own watch's
  updates. `LocationUpdated` carries `id` for manual correlation in `#[OnNative]`.
- Prefer `minDistance()` over a tight `interval()` for battery; a screen mutating
  state on every update should set `protected bool $forceFullFrames = true;`.

### Position Sharing (live-location beacon)

Chain `->share(...)` on a watch (or use `Geolocation::sharePosition(...)`) to have
the NATIVE layer POST the latest fix to a server on an interval — no PHP involved,
survives backgrounding/process death/reboot. Share implies `->background()`.

> Opt-in: `->background()` / `->share(...)` need `NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION=true`
> in `.env` (and a plain foreground service needs `NATIVEPHP_GEOLOCATION_FOREGROUND_SERVICE=true`).
> Without it the build ships foreground-only permissions and background recording won't run.
> One-shot `getCurrentPosition()` and plain `watchPosition()` need no flag.

@verbatim
<code-snippet name="Share position with a server" lang="php">
use Native\Mobile\Facades\Geolocation;

$this->watchId = Geolocation::watchPosition(fineAccuracy: true)
    ->minDistance(5)
    ->share(
        url: $resolvedEndpoint,           // absolute URL — resolve {placeholders} first
        token: $bearerToken,              // sent as Authorization: Bearer
        interval: 60,                     // seconds between POSTs (min 5)
        expiresAt: now()->addHours(4),    // native expiry; null = never
        stopWatchOnExpiry: true,          // also stop recording at expiry (buffer kept)
    )
    ->getId();

Geolocation::stopBackgroundWatch($this->watchId);  // stop sharing + recording
</code-snippet>
@endverbatim

- Wire shape: `POST {"points":[{latitude, longitude, accuracy, speed?, heading?,
  altitude?, timestamp(ISO-8601)}]}` — one latest fix per interval, 15 s timeout.
- Beacon semantics: failed POSTs are dropped (no retry queue); the durable buffer
  still records every fix, so upload the full trail via drain→trim if needed.
- Observe via `Geolocation::backgroundWatchStatus()`: `shareActive`, `lastShareAt`,
  `lastShareStatus` (HTTP code, 0 = network failure, -1 = expired).
- Invalid expiry values throw instead of becoming an unlimited share. Android
  checks expiry on its timer; iOS enforces it before the next delivered fix.

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Geolocation in JavaScript" lang="javascript">
import { geolocation } from '#nativephp';

// Get location using network positioning
await geolocation.getCurrentPosition();

// Get location using GPS (high accuracy)
await geolocation.getCurrentPosition().fineAccuracy(true).id('current-loc');

// Check permissions
await geolocation.checkPermissions();

// Request permissions
await geolocation.requestPermissions().remember();
</code-snippet>
@endverbatim

### Handling Location Events

#### PHP

@verbatim
<code-snippet name="Location Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\Geolocation\LocationReceived;

#[OnNative(LocationReceived::class)]
public function handleLocationReceived(
    bool $success,
    ?float $latitude,
    ?float $longitude,
    ?float $accuracy,
    ?int $timestamp,
    ?string $provider,
    ?string $error
) {
    if ($success) {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }
}
</code-snippet>
@endverbatim

### Events

- `LocationReceived` - Location data received
  - `bool $success`, `float $latitude`, `float $longitude`, `float $accuracy`
  - `int $timestamp`, `string $provider`, `string $error`

- `PermissionStatusReceived` - Permission check result
  - Values: `'granted'`, `'denied'`, `'not_determined'`

- `PermissionRequestResult` - Permission request result
  - Special value: `'permanently_denied'` indicates user blocked access

### Best Practices

- **Privacy**: Explain location necessity before requesting
- **Performance**: GPS uses more battery than network location
- **Indoor**: GPS may not work well indoors
- **Caching**: Consider caching recent locations for better UX
