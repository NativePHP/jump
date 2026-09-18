<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Concerns\SearchesDocs;
use App\NativeComponents\Layouts\JumpTabsLayout;
use App\Support\DiscoveredServers;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Scanner;
use Nativephp\MobileOta\Facades\Ota;

/**
 * Scanner tab — the Jump home / launcher.
 *
 * Marketing hero + Playground card + quick-access cards. LAN dev-server
 * discovery is now app-wide: the "N servers nearby" pill floats over every tab
 * from {@see JumpTabsLayout} and its state lives
 * in {@see DiscoveredServers} (fed via {@see InteractsWithDiscovery}).
 * Scanning a jump:// QR still connects from here.
 */
class Home extends NativeComponent
{
    use InteractsWithDiscovery;
    use SearchesDocs;

    protected bool $hidesNavBar = true;

    public bool $showHow = false;

    /** Lines describing the last OTA check or download, newest run only. */
    public array $otaLog = [];

    public string $otaState = 'idle';

    public function navTitle(): string
    {
        return 'Jump';
    }

    public function mount(): void
    {
        $this->startDiscovery();
    }

    /**
     * Ask Bifrost what this shell should be running. Everything the answer
     * turned on is written to the panel: what we asked with, what came back,
     * and why it decided what it decided.
     */
    public function checkForOtaUpdate(): void
    {
        $identity = Ota::identity();
        $this->otaState = 'checking';
        $this->otaLog = [
            'endpoint  '.(config('nativephp-ota.endpoint') ?: '(unset)'),
            'project   '.(config('nativephp-ota.project_uuid') ?: '(unset)'),
            'arc       '.$identity['arc'],
            'shell     '.$this->shorten($identity['shell_fingerprint']).'  algo '.$identity['fingerprint_algorithm'],
            'holding   '.($identity['release_uuid'] ? $this->shorten($identity['release_uuid']) : '(no release yet)'),
            '',
        ];

        $result = Ota::check();
        logger()->info('OTA check', $result);

        $this->otaLog[] = match (true) {
            ! empty($result['available']) => 'AVAILABLE '.$this->shorten($result['release'] ?? ''),
            ! empty($result['upToDate']) => 'UP TO DATE',
            default => 'NO UPDATE — '.($result['reason'] ?? 'unknown'),
        };

        foreach (['requested', 'status', 'release', 'sha256', 'size', 'reason', 'download_url'] as $key) {
            if (! empty($result[$key])) {
                $this->otaLog[] = sprintf('%-9s %s', $key, $this->shorten((string) $result[$key], 52));
            }
        }

        $this->otaState = ! empty($result['available']) ? 'available' : 'idle';
    }

    /**
     * Fetch the payload and queue it. Core extracts it on the next launch, so
     * nothing visible changes until the app is restarted.
     */
    public function downloadOtaUpdate(): void
    {
        $this->otaState = 'downloading';
        $this->otaLog[] = '';
        $this->otaLog[] = 'downloading…';

        $result = Ota::downloadAndApply();
        logger()->info('OTA download', $result);

        $queued = ! empty($result['queued']) || ! empty($result['applyOnNextBoot']);

        $this->otaLog[] = $queued
            ? 'QUEUED — restart the app to apply'
            : 'FAILED — '.($result['error'] ?? $result['reason'] ?? 'unknown');

        if ($queued) {
            $this->otaLog[] = 'path      '.$this->shorten((string) ($result['path'] ?? ''), 44);
        }

        $this->otaState = $queued ? 'queued' : 'idle';
    }

    private function shorten(?string $value, int $length = 13): string
    {
        $value = (string) $value;

        return strlen($value) > $length ? substr($value, 0, $length).'…' : $value;
    }

    public function scan(): void
    {
        Scanner::scan()->prompt('Scan a Jump QR code');
    }

    /**
     * A scanned jump://connect?host=&port= QR connects to that dev server.
     * Routed through the trait's connect() so the first-time escape-hatch
     * coaching sheet gates this path too.
     */
    #[On(CodeScanned::class)]
    public function codeScanned(string $data): void
    {
        if (preg_match('#[?&]host=([^&]+).*?[?&]port=([^&]+)#', $data, $m)
            || preg_match('#"host"\s*:\s*"([^"]+)".*?"port"\s*:\s*"?([^",}]+)#', $data, $m)) {
            $this->connect(urldecode($m[1]), urldecode($m[2]));
        }
    }

    public function openHow(): void
    {
        $this->showHow = true;
    }

    /**
     * Idempotent close. The bottom-sheet fires @dismiss on its own slide-down
     * animation, so tapping the X (which also closes) must not toggle — a
     * toggle would flip false→true off the second event and reopen the sheet.
     */
    public function closeHow(): void
    {
        $this->showHow = false;
    }

    public function playground(): void
    {
        $this->navigate('/playground');
    }

    public function goDocs(): void
    {
        $this->navigate('/docs');
    }

    public function goVideos(): void
    {
        $this->navigate('/videos');
    }

    /**
     * Opens a partner agency's site in the system browser. Called from the
     * "Agency Partners" cards (@press="openPartner('...')").
     */
    public function openPartner(string $url): void
    {
        Browser::open($url);
    }

    /**
     * Vetted NativePHP consulting partners, mirrored from
     * nativephp.com/consulting. `logo` is a remote raster URL where the partner
     * publishes one (the native <image> renderer needs http(s) raster, not the
     * site's bundled SVG); null falls back to a lettered monogram tile.
     *
     * @return list<array{name: string, tagline: string, url: string, logo: ?string}>
     */
    protected function partners(): array
    {
        return [
            [
                'name' => 'Nexcalia',
                'tagline' => 'Smart tools for scheduling & visitor management.',
                'url' => 'https://www.nexcalia.com/?ref=nativephp',
                'logo' => null,
            ],
            [
                'name' => 'Web Mavens',
                'tagline' => 'Laravel Partners crafting secure, SOC 2-ready apps.',
                'url' => 'https://www.webmavens.com/?ref=nativephp',
                'logo' => null,
            ],
            [
                'name' => 'Synergi Tech',
                'tagline' => 'Bespoke software for complex infrastructure.',
                'url' => 'https://synergitech.co.uk/partners/nativephp/',
                'logo' => 'https://synergitech.co.uk/logo.png',
            ],
        ];
    }

    public function render(): View
    {
        return view('native.home', [
            'partners' => $this->partners(),
            'otaRelease' => Ota::currentRelease(),
        ]);
    }
}
