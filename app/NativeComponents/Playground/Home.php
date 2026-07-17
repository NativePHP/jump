<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Biometrics;
use Native\Mobile\Facades\Camera;
use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Geolocation;
use Native\Mobile\Facades\Haptics;
use Native\Mobile\Facades\Network;
use Native\Mobile\Facades\System;
use NativePHP\LocalNotifications\Events\PermissionGranted;
use NativePHP\LocalNotifications\Facades\LocalNotifications;
use NativePHP\MediaPlayer\Facades\MediaPlayer;

/**
 * The NativePHP Playground — ten core APIs on one scrolling page. Each card
 * shows the PHP you'd write (the code block) directly above a live example
 * that runs that exact call through the native bridge.
 */
class Home extends NativeComponent
{
    /** Growing media lists (photos, recordings) — keep PHP and native trees in lockstep. */
    protected bool $forceFullFrames = true;

    // ── Camera ─────────────────────────────────────────────────────

    /** @var array<int, string> */
    public array $photos = [];

    public string $cameraStatus = '';

    // ── Gallery ────────────────────────────────────────────────────

    /** @var array<int, string> */
    public array $picked = [];

    public string $galleryStatus = '';

    // ── Video ──────────────────────────────────────────────────────

    public string $videoStatus = '';

    // ── Location ───────────────────────────────────────────────────

    public ?float $latitude = null;

    public ?float $longitude = null;

    public ?float $accuracy = null;

    public bool $locating = false;

    public string $locationStatus = '';

    /** Set when the OS has permanently denied location — the button offers Settings instead. */
    public bool $locationBlocked = false;

    // ── Notifications ──────────────────────────────────────────────

    public bool $notifyGranted = false;

    // ── Biometrics ─────────────────────────────────────────────────

    public string $bioState = 'locked';

    // ── Device / Flashlight / Network / Haptics ────────────────────

    /** @var array<string, mixed> */
    public array $deviceInfo = [];

    /** @var array<string, mixed> */
    public array $battery = [];

    public bool $torchOn = false;

    /** @var array<string, mixed> */
    public array $network = [];

    public int $buzzes = 0;

    public function navTitle(): string
    {
        return 'Playground';
    }

    public function mount(): void
    {
        // Device + network are synchronous reads — populate them up front.
        $this->readDevice();
        $this->readNetwork();
    }

    // ── Camera ─────────────────────────────────────────────────────

    public function takePhoto(): void
    {
        Camera::getPhoto()
            ->photoTaken(function ($event) {
                $this->photos[] = $event->path;
                $this->cameraStatus = '';
            })
            ->photoCancelled(function () {
                $this->cameraStatus = 'Capture cancelled';
            })
            ->permissionDenied(function () {
                $this->cameraStatus = 'Camera permission denied — enable it in Settings';
            });
    }

    public function clearPhotos(): void
    {
        $this->photos = [];
    }

    // ── Gallery ────────────────────────────────────────────────────

    public function pickImages(): void
    {
        Camera::pickImages('images', true, 4)
            ->mediaSelected(function ($event) {
                // MediaSelected carries cancellation in-band — no separate cancel event.
                if ($event->cancelled || ! $event->success) {
                    $this->galleryStatus = $event->error ?: 'Selection cancelled';

                    return;
                }

                $this->galleryStatus = '';
                $this->picked = array_column($event->files, 'path');
            });
    }

    // ── Video ──────────────────────────────────────────────────────

    public function recordVideo(): void
    {
        Camera::recordVideo()
            ->maxDuration(30)
            ->videoRecorded(function ($event) {
                $this->videoStatus = '';
                $this->storeVideo($event->path);
            })
            ->videoCancelled(function () {
                $this->videoStatus = 'Recording cancelled';
            })
            ->permissionDenied(function () {
                $this->videoStatus = 'Camera permission denied — enable it in Settings';
            });
    }

    public function playVideo(string $name): void
    {
        MediaPlayer::present(storage_path('app/videos/'.basename($name)));
    }

    public function deleteVideo(string $name): void
    {
        @unlink(storage_path('app/videos/'.basename($name)));
        Dialog::toast('Video deleted');
    }

    /**
     * On device, storage_path() points at the app-container storage which
     * doesn't ship with a videos/ dir — create it before moving the file.
     */
    protected function storeVideo(string $sourcePath): void
    {
        if (! is_file($sourcePath)) {
            $this->videoStatus = "Video file not found: {$sourcePath}";

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
            $this->videoStatus = 'Could not move the video into app storage.';
        }
    }

    /** @return array<int, array{name: string, path: string, size: string, date: string}> */
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

    // ── Location ───────────────────────────────────────────────────

    public function getLocation(): void
    {
        $this->locating = true;
        $this->locationBlocked = false;
        $this->locationStatus = 'Checking permission…';

        // Check first — CheckPermissions always replies immediately. Request
        // only when undetermined: iOS's requestWhenInUseAuthorization() is a
        // silent no-op (no delegate callback, so no result event) when the
        // status is already decided, which would leave the spinner hanging.
        Geolocation::checkPermissions()->permissionStatusReceived(function ($event) {
            match ($event->location) {
                'granted' => $this->fetchPosition(),
                // notDetermined comes back as 'denied' from the plugin — that's
                // the only state iOS/Android will actually show a dialog for.
                'denied' => $this->askLocationPermission(),
                // 'permanently_denied' (and anything else): the OS won't prompt
                // again, so send the user to Settings.
                default => $this->blockLocation(),
            };
        });
    }

    public function openLocationSettings(): void
    {
        System::appSettings();
    }

    protected function askLocationPermission(): void
    {
        $this->locationStatus = 'Requesting permission…';

        Geolocation::requestPermissions()->permissionRequestResult(function ($event) {
            match ($event->location ?? 'denied') {
                'granted' => $this->fetchPosition(),
                'permanently_denied' => $this->blockLocation(),
                default => $this->stopLocating('Permission denied.'),
            };
        });
    }

    protected function fetchPosition(): void
    {
        $this->locationStatus = 'Getting a fix…';

        Geolocation::getCurrentPosition()->locationReceived(function ($event) {
            $this->locating = false;

            if (! $event->success) {
                $this->locationStatus = 'Error: '.$event->error;

                return;
            }

            $this->locationStatus = '';
            $this->latitude = $event->latitude;
            $this->longitude = $event->longitude;
            $this->accuracy = $event->accuracy;
        });
    }

    protected function stopLocating(string $message): void
    {
        $this->locating = false;
        $this->locationStatus = $message;
    }

    protected function blockLocation(): void
    {
        $this->locating = false;
        $this->locationBlocked = true;
        $this->locationStatus = 'Location is off for Jump. Enable it in Settings, then tap again.';
    }

    // ── Notifications ──────────────────────────────────────────────

    public function requestNotifyPermission(): void
    {
        // The released plugin has no fluent permissionGranted() callback —
        // the result arrives via the PermissionGranted event.
        LocalNotifications::requestPermission();
    }

    #[On(PermissionGranted::class)]
    public function handleNotifyPermission(bool $granted): void
    {
        $this->notifyGranted = $granted;
        Dialog::toast($granted ? 'Notifications enabled!' : 'Permission denied');
    }

    public function sendNotification(): void
    {
        LocalNotifications::send('playground-hello')
            ->title('Hello from Laravel!')
            ->body('This notification was sent by PHP running on your device.')
            ->url('/playground');
    }

    public function sendDelayedNotification(): void
    {
        LocalNotifications::send('playground-delayed')
            ->title('Scheduled Notification')
            ->body('This was scheduled 10 seconds ago!')
            ->url('/playground')
            ->delay(10);

        Dialog::toast('Arriving in 10 seconds…');
    }

    // ── Biometrics ─────────────────────────────────────────────────

    public function authenticate(): void
    {
        $this->bioState = 'prompting';

        Biometrics::prompt()
            ->completed(function ($event) {
                $this->bioState = $event->success ? 'unlocked' : 'failed';
            });
    }

    public function lockAgain(): void
    {
        $this->bioState = 'locked';
    }

    // ── Device ─────────────────────────────────────────────────────

    public function readDevice(): void
    {
        $this->deviceInfo = json_decode(Device::getInfo() ?? '{}', true) ?: [];
        $this->battery = json_decode(Device::getBatteryInfo() ?? '{}', true) ?: [];
    }

    // ── Flashlight ─────────────────────────────────────────────────

    public function toggleTorch(): void
    {
        $result = Device::flashlight();

        $this->torchOn = ($result['success'] ?? false) ? (bool) $result['state'] : false;
    }

    // ── Network ────────────────────────────────────────────────────

    public function readNetwork(): void
    {
        $this->network = (array) (Network::status() ?? []);
    }

    // ── Haptics ────────────────────────────────────────────────────

    public function vibrate(): void
    {
        Haptics::vibrate();
        $this->buzzes++;
    }

    public function render(): View
    {
        return view('native.playground.home', [
            'videos' => $this->videos(),
            'code' => $this->code(),
        ]);
    }

    /**
     * The code block shown on each card — the same call the card's live
     * example runs.
     *
     * @return array<string, string>
     */
    protected function code(): array
    {
        return [
            'camera' => "Camera::getPhoto()\n    ->photoTaken(fn (\$event) => \$this->photos[] = \$event->path);",
            'gallery' => "Camera::pickImages('images', multiple: true, max: 4)\n    ->mediaSelected(fn (\$event) => \$event->files);",
            'video' => "Camera::recordVideo()->maxDuration(30)\n    ->videoRecorded(fn (\$event) => \$event->path);",
            'location' => "Geolocation::getCurrentPosition()\n    ->locationReceived(fn (\$event) => \$event->latitude);",
            'notifications' => "LocalNotifications::send('hello')\n    ->title('Hello from Laravel!')\n    ->body('Sent by PHP running on your device.');",
            'biometrics' => "Biometrics::prompt()\n    ->completed(fn (\$event) => \$event->success);",
            'device' => "\$info = Device::getInfo();\n\$battery = Device::getBatteryInfo();",
            'flashlight' => 'Device::flashlight();',
            'network' => '$status = Network::status();',
            'haptics' => 'Haptics::vibrate();',
        ];
    }
}
