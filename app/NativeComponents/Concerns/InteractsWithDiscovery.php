<?php

namespace App\NativeComponents\Concerns;

use App\NativeComponents\Layouts\JumpTabsLayout;
use App\Support\DiscoveredServers;
use Native\Mobile\Attributes\On;
use NativePHP\Discovery\Events\ServerFound;
use NativePHP\Discovery\Events\ServerLost;
use NativePHP\Discovery\Facades\Discovery;

/**
 * Feeds the app-wide {@see DiscoveredServers} store from whatever tab is
 * currently active, and owns the "servers nearby" pill's local UI state.
 *
 * Native discovery events are delivered only to the mounted component, so every
 * tab (`use`s this trait) listens and writes into the shared store; the
 * floating pill — rendered app-wide from {@see JumpTabsLayout}
 * — then reads the store on any tab. `$showServers` is per-screen (only one tab
 * is active at a time) and drives the server-list bottom sheet.
 */
trait InteractsWithDiscovery
{
    /** Whether the discovered-servers bottom sheet is open on this screen. */
    public bool $showServers = false;

    /**
     * Start browsing the LAN. Call from each tab's `mount()` — NOT from a
     * service provider: the native browser reports already-running servers in
     * an immediate burst right after `start()`, and `#[On(ServerFound)]`
     * listeners are only registered as a component mounts. Starting at boot
     * (before any listener exists) drops that first burst, so servers already
     * up when the app opens never present. Idempotent, so re-calling it as
     * later tabs mount is a harmless no-op.
     */
    public function startDiscovery(): void
    {
        Discovery::start();
    }

    #[On(ServerFound::class)]
    public function serverFound(string $host, string $port, string $name = 'Jump server'): void
    {
        app(DiscoveredServers::class)->put($host, $port, $name);
    }

    #[On(ServerLost::class)]
    public function serverLost(string $host, string $port): void
    {
        $store = app(DiscoveredServers::class);
        $store->forget($host, $port);

        if ($store->isEmpty()) {
            $this->showServers = false;
        }
    }

    /** Toggle the server-list sheet (bound to the floating pill's tap). */
    public function toggleServers(): void
    {
        $this->showServers = ! $this->showServers;
    }

    /** Close the server-list sheet (bound to the sheet's @dismiss). */
    public function closeServers(): void
    {
        $this->showServers = false;
    }

    /** Connect to a discovered dev server and dismiss the sheet. */
    public function connect(string $host, string $port): void
    {
        $this->showServers = false;
        Discovery::connect($host, $port);
    }
}
