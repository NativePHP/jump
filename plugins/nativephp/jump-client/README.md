# NativePHP Mobile — Jump Client

The session client for the Jump app. It connects to a `php artisan native:jump`
dev server and renders the developer's app in place: native-ui apps are
streamed over a WebSocket into the shell's own renderers, WebView apps are
forwarded over HTTP.

This plugin is specific to Jump. Jump pairs it with `nativephp/mobile-discovery`,
which finds the servers on the LAN; this plugin does not depend on it.

- **iOS:** `JumpBridgeRelay` + `JumpElementRuntime`, and `JumpEscapeHatchGesture`.
- **Android:** the same pair (OkHttp WebSocket), and `EscapeHatchGesture`.

## PHP API

```php
use NativePHP\JumpClient\Facades\JumpClient;

JumpClient::connect($host, $port);   // connect to a dev server
```

When a session ends (the server goes away, or the user swipes out) the shell
fires a `__jumpResume` native event so the local app can repaint and resync.

## Escape hatch

A three-finger swipe right exits any remote app back to the local Jump home.
It is attached the first time `connect()` runs and does nothing unless a
remote session is live.

## Build hook

The `pre_compile` hook `native:jump-client:patch-shell` runs before every
native compile and:

- forks the shell's `NativeElementBridge` (iOS and Android) so element events
  go to the remote dev server while a Jump session is live;
- on Android, rewrites `network_security_config.xml` to allow cleartext in
  release builds, because Jump talks plain HTTP/WS to LAN dev servers.

## Install (local dev)

Add a path repository to the app's `composer.json`, require the package, then
enable it in `app/Providers/NativeServiceProvider@plugins()`.
