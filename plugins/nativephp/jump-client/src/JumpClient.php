<?php

namespace NativePHP\JumpClient;

/**
 * Jump session client.
 *
 * Connects the app to a `php artisan native:jump` dev server and renders the
 * developer's app in place (streamed native UI, or a forwarded WebView app).
 * A 3-finger swipe-right exits the session back to the local app.
 */
class JumpClient
{
    /**
     * Connect to a dev server.
     *
     * Hands the host/port to the native relay, which fetches `/jump/info`,
     * opens the bridge WebSocket and takes over the screen with the remote
     * app until the session ends or the user swipes out.
     */
    public function connect(string $host, string|int $port): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('JumpClient.Connect', json_encode([
                'host' => $host,
                'port' => (string) $port,
            ]));
        }
    }
}
