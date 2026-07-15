<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Device;

class FlashlightDemo extends NativeComponent
{
    public bool $on = false;

    public function navTitle(): string
    {
        return 'Flashlight';
    }

    public function toggle(): void
    {
        $result = Device::flashlight();

        $this->on = ($result['success'] ?? false) ? (bool) $result['state'] : false;
    }

    public function render(): View
    {
        return view('native.playground.flashlight-demo');
    }
}
