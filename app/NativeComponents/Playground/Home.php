<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Motion\ShakeDetected;

class Home extends NativeComponent
{
    public string $gesture = 'Double-tap this card · or shake the device';

    public int $shakes = 0;

    public function navTitle(): string
    {
        return 'Playground';
    }

    /**
     * Entering the playground swaps the chrome to its own tab bar, so there is
     * no native back chevron out — this is the explicit way back to Jump.
     */
    public function exitPlayground(): void
    {
        $this->navigate('/');
    }

    public function doubleTapped(): void
    {
        $this->gesture = 'Double-tapped!';
    }

    #[On(ShakeDetected::class)]
    public function onShake(): void
    {
        $this->shakes++;
        $this->gesture = "Shaken {$this->shakes}×!";
    }

    /** @return array<int, array<string, string>> */
    public function featured(): array
    {
        return [
            ['id' => 'camera', 'title' => 'Camera', 'subtitle' => 'Native capture', 'ios' => 'camera.fill', 'android' => 'photo_camera', 'color' => '#7C3AED', 'url' => '/playground/media/camera'],
            ['id' => 'scanner', 'title' => 'Scanner', 'subtitle' => 'QR & barcodes', 'ios' => 'qrcode.viewfinder', 'android' => 'qr_code_scanner', 'color' => '#0EA5E9', 'url' => '/playground/media/scanner'],
            ['id' => 'biometrics', 'title' => 'Biometrics', 'subtitle' => 'Face & touch', 'ios' => 'faceid', 'android' => 'fingerprint', 'color' => '#10B981', 'url' => '/playground/system/biometrics'],
            ['id' => 'location', 'title' => 'Location', 'subtitle' => 'GPS & permissions', 'ios' => 'location.fill', 'android' => 'my_location', 'color' => '#F59E0B', 'url' => '/playground/system/geolocation'],
            ['id' => 'notifications', 'title' => 'Notifications', 'subtitle' => 'Local reminders', 'ios' => 'bell.badge.fill', 'android' => 'notifications', 'color' => '#EC4899', 'url' => '/playground/notify/local'],
            ['id' => 'microphone', 'title' => 'Microphone', 'subtitle' => 'Record audio', 'ios' => 'mic.fill', 'android' => 'mic', 'color' => '#14B8A6', 'url' => '/playground/media/microphone'],
        ];
    }

    public function render(): View
    {
        return view('native.playground.home', ['featured' => $this->featured()]);
    }
}
