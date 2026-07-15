<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Microphone;
use Native\Mobile\Facades\Share;
use NativePHP\MediaPlayer\Events\PlaybackEnded;
use NativePHP\MediaPlayer\Facades\MediaPlayer;

class MicrophoneDemo extends NativeComponent
{
    /** Growing list screen — keep PHP and native trees in lockstep. */
    protected bool $forceFullFrames = true;

    public string $recordingState = 'idle';

    public string $status = '';

    public ?string $playing = null;

    public function navTitle(): string
    {
        return 'Microphone';
    }

    public function startRecording(): void
    {
        Microphone::record()
            ->microphoneRecorded(function ($event) {
                $this->recordingState = 'idle';
                $this->storeRecording($event->path);
            })
            ->microphoneCancelled(function () {
                $this->recordingState = 'idle';
                $this->status = 'Recording cancelled';
            })
            ->start();

        $this->recordingState = 'recording';
        $this->status = '';
    }

    /**
     * On device, storage_path() points at the app-container storage which
     * doesn't ship with an audio/ dir — create it before moving the file.
     */
    protected function storeRecording(string $sourcePath): void
    {
        if (! is_file($sourcePath)) {
            $this->status = "Recording file not found: {$sourcePath}";

            return;
        }

        $directory = storage_path('app/audio');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $target = $directory.'/audio_'.time().'_'.uniqid().'.m4a';

        if (@rename($sourcePath, $target) || @copy($sourcePath, $target)) {
            Dialog::toast('Recording saved!');
        } else {
            $this->status = 'Could not move the recording into app storage.';
        }
    }

    public function pauseRecording(): void
    {
        Microphone::pause();
        $this->recordingState = 'paused';
    }

    public function resumeRecording(): void
    {
        Microphone::resume();
        $this->recordingState = 'recording';
    }

    public function stopRecording(): void
    {
        Microphone::stop();
    }

    #[Poll(1000)]
    public function pollStatus(): void
    {
        if ($this->recordingState !== 'idle') {
            $this->status = 'Native status: '.Microphone::getStatus();
        }
    }

    public function playAudio(string $name): void
    {
        if ($this->playing === $name) {
            $this->stopPlayback();

            return;
        }

        MediaPlayer::play(storage_path('app/audio/'.basename($name)));
        $this->playing = $name;
    }

    public function stopPlayback(): void
    {
        MediaPlayer::stop();
        $this->playing = null;
    }

    #[On(PlaybackEnded::class)]
    public function handlePlaybackEnded(): void
    {
        $this->playing = null;
    }

    public function shareAudio(string $name): void
    {
        Share::file('Listen to this!', 'Recorded with the NativePHP Playground', storage_path('app/audio/'.basename($name)));
    }

    public function deleteAudio(string $name): void
    {
        if ($this->playing === $name) {
            $this->stopPlayback();
        }

        @unlink(storage_path('app/audio/'.basename($name)));
        Dialog::toast('Recording deleted');
    }

    /** @return array<int, array{name: string, size: string, date: string}> */
    public function recordings(): array
    {
        $files = glob(storage_path('app/audio/*.m4a')) ?: [];

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return array_map(fn ($file) => [
            'name' => basename($file),
            'size' => number_format(filesize($file) / 1024).' KB',
            'date' => date('M j, H:i', filemtime($file)),
        ], $files);
    }

    public function render(): View
    {
        return view('native.playground.microphone-demo', ['recordings' => $this->recordings()]);
    }
}
