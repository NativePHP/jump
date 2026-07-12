<?php

namespace NativePHP\Discovery\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A previously-discovered dev server disappeared from the LAN (browser drop or
 * a failed liveness probe against `/jump/info`).
 *
 * Keyed by `host` + `port` so a listener can remove the matching entry.
 */
class ServerLost
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $host,
        public string $port,
    ) {}
}
