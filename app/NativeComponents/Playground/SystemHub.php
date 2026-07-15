<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class SystemHub extends NativeComponent
{
    public function navTitle(): string
    {
        return 'System';
    }

    /** @return array<int, array{title: string, demos: array<int, array<string, string>>}> */
    public function groups(): array
    {
        return [
            [
                'title' => 'Security',
                'demos' => [
                    ['id' => 'biometrics', 'title' => 'Biometrics', 'subtitle' => 'Face ID / fingerprint authentication', 'icon' => 'faceid', 'color' => '#7C3AED', 'url' => '/playground/system/biometrics'],
                    ['id' => 'secure-storage', 'title' => 'Secure Storage', 'subtitle' => 'Encrypted key-value storage', 'icon' => 'lock.fill', 'color' => '#6366F1', 'url' => '/playground/system/secure-storage'],
                ],
            ],
            [
                'title' => 'Device & Sensors',
                'demos' => [
                    ['id' => 'device', 'title' => 'Device Info', 'subtitle' => 'Identifiers, hardware and battery', 'icon' => 'iphone', 'color' => '#0EA5E9', 'url' => '/playground/system/device'],
                    ['id' => 'flashlight', 'title' => 'Flashlight', 'subtitle' => 'Toggle the torch', 'icon' => 'flashlight.on.fill', 'color' => '#F59E0B', 'url' => '/playground/system/flashlight'],
                    ['id' => 'network', 'title' => 'Network', 'subtitle' => 'Connection status and type', 'icon' => 'wifi', 'color' => '#14B8A6', 'url' => '/playground/system/network'],
                    ['id' => 'haptics', 'title' => 'Haptics', 'subtitle' => 'Vibration feedback', 'icon' => 'iphone.radiowaves.left.and.right', 'color' => '#EC4899', 'url' => '/playground/system/haptics'],
                    ['id' => 'browser', 'title' => 'Browser', 'subtitle' => 'In-app, auth and system browser', 'icon' => 'safari.fill', 'color' => '#0891B2', 'url' => '/playground/system/browser'],
                ],
            ],
        ];
    }

    public function render(): View
    {
        return view('native.playground.hub', ['groups' => $this->groups()]);
    }
}
