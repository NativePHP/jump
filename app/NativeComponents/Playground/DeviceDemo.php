<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Device;

class DeviceDemo extends NativeComponent
{
    public ?string $deviceId = null;

    /** @var array<string, mixed> */
    public array $info = [];

    /** @var array<string, mixed> */
    public array $battery = [];

    public function navTitle(): string
    {
        return 'Device Info';
    }

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->deviceId = Device::getId();
        $this->info = json_decode(Device::getInfo() ?? '{}', true) ?: [];
        $this->battery = json_decode(Device::getBatteryInfo() ?? '{}', true) ?: [];
    }

    public function render(): View
    {
        return view('native.playground.device-demo');
    }
}
