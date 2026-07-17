<?php

namespace NativePHP\Geolocation\Components;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class Map extends NativeBladeComponent
{
    protected bool $isSelfClosing = false;

    protected function elementType(): string
    {
        return 'map';
    }
}
