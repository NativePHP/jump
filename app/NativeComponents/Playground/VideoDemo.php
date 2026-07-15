<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Camera;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Share;
use NativePHP\MediaPlayer\Facades\MediaPlayer;

class VideoDemo extends NativeComponent
{
    /** Growing list screen — keep PHP and native trees in lockstep. */
    protected bool $forceFullFrames = true;

    public float $maxDuration = 30;

    public string $status = '';

    public function navTitle(): string
    {
        return 'Video';
    }

    public function recordVideo(): void
    {
        Camera::recordVideo()
            ->maxDuration((int) $this->maxDuration)
            ->videoRecorded(function ($event) {
                $this->status = '';
                $this->storeVideo($event->path);
            })
            ->videoCancelled(function () {
                $this->status = 'Recording cancelled';
            })
            ->permissionDenied(function () {
                $this->status = 'Camera permission denied — enable it in Settings';
            });
    }

    /**
     * On device, storage_path() points at the app-container storage which
     * doesn't ship with a videos/ dir — create it before moving the file.
     */
    protected function storeVideo(string $sourcePath): void
    {
        if (! is_file($sourcePath)) {
            $this->status = "Video file not found: {$sourcePath}";

            return;
        }

        $directory = storage_path('app/videos');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $target = $directory.'/video_'.time().'_'.uniqid().'.mp4';

        if (@rename($sourcePath, $target) || @copy($sourcePath, $target)) {
            Dialog::toast('Video recorded!');
        } else {
            $this->status = 'Could not move the video into app storage.';
        }
    }

    public function playVideo(string $name): void
    {
        MediaPlayer::present(storage_path('app/videos/'.basename($name)));
    }

    public function shareVideo(string $name): void
    {
        Share::file('Check this out!', 'Recorded with the NativePHP Playground', storage_path('app/videos/'.basename($name)));
    }

    public function deleteVideo(string $name): void
    {
        @unlink(storage_path('app/videos/'.basename($name)));
        Dialog::toast('Video deleted');
    }

    /** @return array<int, array{name: string, size: string, date: string}> */
    public function videos(): array
    {
        $files = glob(storage_path('app/videos/*.mp4')) ?: [];

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return array_map(fn ($file) => [
            'name' => basename($file),
            'path' => $file,
            'size' => number_format(filesize($file) / 1048576, 1).' MB',
            'date' => date('M j, H:i', filemtime($file)),
        ], $files);
    }

    public function render(): View
    {
        return view('native.playground.video-demo', ['videos' => $this->videos()]);
    }
}
