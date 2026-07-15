<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Camera;
use Native\Mobile\Facades\Dialog;

class GalleryDemo extends NativeComponent
{
    /** @var array<int, string> */
    public array $photos = [];

    public string $mediaType = 'images';

    public bool $multiple = true;

    public float $maxItems = 5;

    public string $status = '';

    public function navTitle(): string
    {
        return 'Gallery';
    }

    public function openGallery(): void
    {
        Camera::pickImages($this->mediaType, $this->multiple, (int) $this->maxItems)
            ->mediaSelected(function ($event) {
                // MediaSelected carries cancellation in-band — no separate cancel event.
                if ($event->cancelled || ! $event->success) {
                    $this->status = $event->error ?: 'Selection cancelled';

                    return;
                }

                $this->status = '';
                $this->photos = [];

                foreach ($event->files as $file) {
                    if (($file['type'] ?? 'image') === 'video') {
                        Dialog::toast('Videos are not supported yet');

                        continue;
                    }

                    $this->photos[] = $file['path'];
                }

                if (count($this->photos)) {
                    Dialog::toast(count($this->photos) > 1 ? 'Images selected!' : 'Image selected!');
                }
            });
    }

    public function render(): View
    {
        return view('native.playground.gallery-demo');
    }
}
