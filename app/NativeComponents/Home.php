<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Concerns\SearchesDocs;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Scanner;
use NativePHP\Discovery\Facades\Discovery;

/**
 * Scanner tab — the Jump home / launcher.
 *
 * Marketing hero + Playground card + quick-access cards. LAN dev-server
 * discovery is now app-wide: the "N servers nearby" pill floats over every tab
 * from {@see \App\NativeComponents\Layouts\JumpTabsLayout} and its state lives
 * in {@see \App\Support\DiscoveredServers} (fed via {@see InteractsWithDiscovery}).
 * Scanning a jump:// QR still connects from here.
 */
class Home extends NativeComponent
{
    use InteractsWithDiscovery;
    use SearchesDocs;

    public bool $showHow = false;

    public function navTitle(): string
    {
        return 'Jump';
    }

    public function mount(): void
    {
        $this->startDiscovery();
    }

    public function scan(): void
    {
        Scanner::scan()->prompt('Scan a Jump QR code');
    }

    /**
     * A scanned jump://connect?host=&port= QR connects to that dev server.
     */
    #[On(CodeScanned::class)]
    public function codeScanned(string $data): void
    {
        if (preg_match('#[?&]host=([^&]+).*?[?&]port=([^&]+)#', $data, $m)
            || preg_match('#"host"\s*:\s*"([^"]+)".*?"port"\s*:\s*"?([^",}]+)#', $data, $m)) {
            Discovery::connect(urldecode($m[1]), urldecode($m[2]));
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
        Dialog::toast('The bundled Playground is coming soon.');
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
                'name'    => 'Nexcalia',
                'tagline' => 'Smart tools for scheduling & visitor management.',
                'url'     => 'https://www.nexcalia.com/?ref=nativephp',
                'logo'    => null,
            ],
            [
                'name'    => 'Web Mavens',
                'tagline' => 'Laravel Partners crafting secure, SOC 2-ready apps.',
                'url'     => 'https://www.webmavens.com/?ref=nativephp',
                'logo'    => null,
            ],
            [
                'name'    => 'Synergi Tech',
                'tagline' => 'Bespoke software for complex infrastructure.',
                'url'     => 'https://synergitech.co.uk/partners/nativephp/',
                'logo'    => 'https://synergitech.co.uk/logo.png',
            ],
        ];
    }

    public function render(): View
    {
        return view('native.home', [
            'partners' => $this->partners(),
        ]);
    }
}
