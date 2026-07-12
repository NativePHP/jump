# NativePHP Mobile — Discovery

Browses the local network for `_jump._tcp` dev servers advertised by
`php artisan native:jump`, so a device can find and connect to a running dev
server without scanning its QR code.

- **iOS:** `NWBrowser` (Network framework), reading `host`/`port`/`name` from
  Bonjour TXT records, with an 8s `/jump/info` liveness probe.
- **Android:** `NsdManager` (Network Service Discovery) with a Wi-Fi multicast
  lock and a serialized resolve queue.

## PHP API

```php
use NativePHP\Discovery\Facades\Discovery;

Discovery::start();                 // begin browsing
Discovery::stop();                  // stop browsing
Discovery::connect($host, $port);   // connect to a discovered server
```

## Events

Dispatched from native code as servers appear / disappear — listen with
`#[OnNative(...)]` on a `NativeComponent`:

- `NativePHP\Discovery\Events\ServerFound` — `{ host, port, name }`
- `NativePHP\Discovery\Events\ServerLost`  — `{ host, port }`

## Install (local dev)

Add a path repository to the app's `composer.json`, require the package, then
enable it in `app/Providers/NativeServiceProvider@plugins()`.
