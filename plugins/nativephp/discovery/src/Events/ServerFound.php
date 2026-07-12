<?php

namespace NativePHP\Discovery\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A `_jump._tcp` dev server was discovered on the LAN.
 *
 * Dispatched from native code (Swift/Kotlin) each time the browser resolves a
 * new server. `host` + `port` are read straight from the advertised TXT
 * records and match what the QR code encodes.
 */
class ServerFound
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $host,
        public string $port,
        public string $name = 'Jump server',
    ) {}
}
