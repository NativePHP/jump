<?php

namespace NativePHP\Discovery\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void start()
 * @method static void stop()
 * @method static void connect(string $host, string|int $port)
 *
 * @see \NativePHP\Discovery\Discovery
 */
class Discovery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NativePHP\Discovery\Discovery::class;
    }
}
