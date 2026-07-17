<?php

namespace NativePHP\Geolocation\Components;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class MapMarker extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'map_marker';
    }
}
