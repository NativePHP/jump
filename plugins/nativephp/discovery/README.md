# NativePHP Mobile — Discovery

Finds `native:jump` dev servers on the local network. It browses for the
`_jump._tcp` services that `php artisan native:jump` advertises and tells your
app as servers appear and disappear, so a device can list running dev servers
without scanning a QR code.

Discovery only finds servers. What the app does with one (for example
connecting to it) is up to the app.

- **iOS:** `NWBrowser` (Network framework), reading `host`/`port`/`name` from
  Bonjour TXT records, with an 8s `/jump/info` liveness probe.
- **Android:** `NsdManager` (Network Service Discovery) with a Wi-Fi multicast
  lock and a serialized resolve queue.

## PHP API

```php
use NativePHP\Discovery\Facades\Discovery;

Discovery::start();   // begin browsing
Discovery::stop();    // stop browsing
```

## Events

Dispatched from native code as servers appear / disappear — listen with
`#[OnNative(...)]` on a `NativeComponent`:

- `NativePHP\Discovery\Events\ServerFound` — `{ host, port, name }`
- `NativePHP\Discovery\Events\ServerLost`  — `{ host, port }`

## Local network permission (iOS)

iOS asks the user before an app browses the local network. The plugin ships a
generic prompt. To say it in your app's own words, set it in
`config/nativephp.php`, which wins over the plugin's:

```php
'permissions' => [
    'NSLocalNetworkUsageDescription' => 'MyApp finds dev servers on your Wi-Fi network.',
],
```

## Install (local dev)

Add a path repository to the app's `composer.json`, require the package, then
enable it in `app/Providers/NativeServiceProvider@plugins()`.
