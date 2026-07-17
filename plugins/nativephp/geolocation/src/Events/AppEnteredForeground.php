<?php

namespace NativePHP\Geolocation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The app returned to the foreground while a background watch was active.
 */
class AppEnteredForeground
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $id,
        public int $timestamp,
    ) {}
}
