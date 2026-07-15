<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Camera;

class CameraDemo extends NativeComponent
{
    /** @var array<int, string> */
    public array $photos = [];

    public string $status = '';

    public function navTitle(): string
    {
        return 'Camera';
    }

    public function takePhoto(): void
    {
        Camera::getPhoto()
            ->photoTaken(function ($event) {
                $this->photos[] = $event->path;
                $this->status = '';
            })
            ->photoCancelled(function () {
                $this->status = 'Capture cancelled';
            })
            ->permissionDenied(function () {
                $this->status = 'Camera permission denied — enable it in Settings';
            });
    }

    public function clearPhotos(): void
    {
        $this->photos = [];
    }

    public function render(): View
    {
        return view('native.playground.camera-demo');
    }
}
