<?php

namespace NativePHP\JumpClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void connect(string $host, string|int $port)
 *
 * @see \NativePHP\JumpClient\JumpClient
 */
class JumpClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NativePHP\JumpClient\JumpClient::class;
    }
}
