<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class MediaHub extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Media';
    }

    /** @return array<int, array{title: string, demos: array<int, array<string, string>>}> */
    public function groups(): array
    {
        return [
            [
                'title' => 'Capture',
                'demos' => [
                    ['id' => 'camera', 'title' => 'Camera', 'subtitle' => 'Take a photo with the native camera', 'icon' => 'camera.fill', 'color' => '#7C3AED', 'url' => '/playground/media/camera'],
                    ['id' => 'video', 'title' => 'Video', 'subtitle' => 'Record, play, share and delete videos', 'icon' => 'video.fill', 'color' => '#EC4899', 'url' => '/playground/media/video'],
                    ['id' => 'gallery', 'title' => 'Gallery', 'subtitle' => 'Pick images from the photo library', 'icon' => 'photo.on.rectangle', 'color' => '#F59E0B', 'url' => '/playground/media/gallery'],
                ],
            ],
            [
                'title' => 'Audio',
                'demos' => [
                    ['id' => 'microphone', 'title' => 'Microphone', 'subtitle' => 'Record, pause, resume and play audio', 'icon' => 'mic.fill', 'color' => '#10B981', 'url' => '/playground/media/microphone'],
                ],
            ],
            [
                'title' => 'Scanning',
                'demos' => [
                    ['id' => 'scanner', 'title' => 'Scanner', 'subtitle' => 'QR codes and barcodes, single or continuous', 'icon' => 'qrcode.viewfinder', 'color' => '#0EA5E9', 'url' => '/playground/media/scanner'],
                ],
            ],
        ];
    }

    public function render(): View
    {
        return view('native.playground.hub', ['groups' => $this->groups()]);
    }
}
