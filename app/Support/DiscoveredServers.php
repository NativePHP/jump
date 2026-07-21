<?php

namespace App\Support;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Layouts\JumpTabsLayout;

/**
 * App-wide registry of LAN dev servers discovered by the `discovery` plugin.
 *
 * Native discovery events (`ServerFound` / `ServerLost`) are delivered only to
 * the currently-active NativeComponent, but the "N servers nearby" pill floats
 * over *every* tab via {@see JumpTabsLayout}'s
 * `floatingOverlay()`. So the server list can't live on one screen — it lives
 * here, a container singleton that survives across events in the long-lived
 * runtime, written by whichever tab is active (via
 * {@see InteractsWithDiscovery}) and read by the
 * layout on each render.
 */
class DiscoveredServers
{
    /** @var array<string, array{host: string, port: string, name: string}> */
    private array $servers = [];

    public function put(string $host, string $port, string $name): void
    {
        $this->servers[$host.':'.$port] = compact('host', 'port', 'name');
    }

    public function forget(string $host, string $port): void
    {
        unset($this->servers[$host.':'.$port]);
    }

    /**
     * Drop everything. Used when returning from a remote Jump session: any
     * ServerLost fired while the session was live was forked to the (dead)
     * remote app instead of this store, so entries here may be phantoms.
     */
    public function flush(): void
    {
        $this->servers = [];
    }

    /** @return list<array{host: string, port: string, name: string}> */
    public function all(): array
    {
        return array_values($this->servers);
    }

    public function count(): int
    {
        return count($this->servers);
    }

    public function isEmpty(): bool
    {
        return $this->servers === [];
    }
}
