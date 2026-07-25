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
use Native\Mobile\Events\System\AppearanceChanged;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Scanner;

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
     * nativephp.com/consulting. Logos are transparent PNGs bundled under
     * public/img/partners (rasterised from the site's SVGs — the native
     * <image> renderer can't draw SVG), with a `-dark` variant swapped in via
     * isDark(). width/height preserve each PNG's aspect ratio at a display
     * size balanced across the set.
     *
     * @return list<array{name: string, url: string, logo: string, width: int, height: int}>
     */
    protected function partners(): array
    {
        return [
            [
                'name' => 'Nexcalia',
                'url' => 'https://www.nexcalia.com/?ref=nativephp',
                'logo' => $this->partnerLogo('nexcalia'),
                'width' => 128,
                'height' => 40,
            ],
            [
                'name' => 'Web Mavens',
                'url' => 'https://www.webmavens.com/?ref=nativephp',
                'logo' => $this->partnerLogo('webmavens'),
                'width' => 208,
                'height' => 28,
            ],
            [
                'name' => 'Synergi Tech',
                'url' => 'https://synergitech.co.uk/partners/nativephp/',
                'logo' => $this->partnerLogo('synergi'),
                'width' => 104,
                'height' => 48,
            ],
        ];
    }

    protected function partnerLogo(string $slug): string
    {
        return public_path('img/partners/'.$slug.(isDark() ? '-dark' : '').'.png');
    }

    /**
     * The partner logos are appearance-specific rasters picked server-side,
     * so a light/dark flip must re-render to swap them.
     */
    #[On(AppearanceChanged::class)]
    public function appearanceChanged(string $mode): void
    {
        // Re-render only.
    }

    public function render(): View
    {
        return view('native.home', [
            'partners' => $this->partners(),
        ]);
    }
}
