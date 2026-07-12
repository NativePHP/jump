<?php

namespace NativePHP\Discovery;

/**
 * LAN dev-server discovery.
 *
 * Drives the native mDNS/Bonjour (iOS) / NSD (Android) browser that looks for
 * `_jump._tcp` services advertised by `php artisan native:jump`. As servers
 * appear and disappear the native side dispatches
 * {@see \NativePHP\Discovery\Events\ServerFound} /
 * {@see \NativePHP\Discovery\Events\ServerLost} events into the app, which a
 * NativeComponent can listen for with `#[OnNative(...)]`.
 *
 * The PHP surface is intentionally thin — the browser lifecycle and the
 * server list live natively; PHP just starts/stops it and reacts to events.
 */
class Discovery
{
    /**
     * Start browsing the local network for `_jump._tcp` dev servers.
     *
     * Idempotent on the native side — calling it while already browsing is a
     * no-op. Each discovered server fires a `ServerFound` event; each dropped
     * server fires a `ServerLost` event.
     */
    public function start(): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Discovery.Start', json_encode([]));
        }
    }

    /**
     * Stop browsing and tear down the native browser.
     */
    public function stop(): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Discovery.Stop', json_encode([]));
        }
    }

    /**
     * Connect to a discovered dev server.
     *
     * Hands the host/port to the host app's existing connect path — the same
     * entrypoint a `jump://connect?host=&port=` deep link or QR scan uses — so
     * all of the connection + remote-render machinery is reused unchanged.
     */
    public function connect(string $host, string|int $port): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Discovery.Connect', json_encode([
                'host' => $host,
                'port' => (string) $port,
            ]));
        }
    }
}
