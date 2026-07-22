<?php

namespace App\NativeComponents\Layouts;

use App\Icons\Android;
use App\Icons\Ios;
use App\NativeComponents\Builds;
use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Docs;
use App\NativeComponents\Home;
use App\NativeComponents\Settings;
use App\Support\DiscoveredServers;
use Native\Mobile\Edge\Elements\Icon;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\UI\Builders\FloatingOverlay;
use Native\Mobile\UI\Concerns\HasFloatingOverlay;

/**
 * The Jump app's root chrome: a native bottom tab bar (SwiftUI TabView /
 * Material 3 NavigationBar) over the four top-level destinations —
 * Scanner (home), Builds (Bifrost cloud), Docs, Videos. Default = Scanner.
 * Indigo (#4F46E5) active tint, matching the "Deep Horizon" palette.
 */
class JumpTabsLayout extends NativeLayout
{
    use HasFloatingOverlay;

    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        // Jump brand lockup in the top bar's principal slot — a `bolt.fill`
        // glyph + wordmark, both in the indigo brand color (#4F46E5). Ports
        // the old app's nav logo verbatim (its `.principal` ToolbarItem was
        // an SF Symbol + Text lockup, not a raster asset).
        // The docs page reader is a pushed level (TOC taps / search results /
        // deep links navigate to /docs/{...}), so it needs `back(true)` for
        // Android's TopAppBar arrow; iOS pushed levels get the automatic
        // NavigationStack chevron either way (manual back only renders at
        // root). $page is only set on reader instances, never on the TOC.
        $isDocsReader = $screen instanceof Docs && $screen->page !== null;

        // The string title isn't drawn (the titleView lockup owns the
        // principal slot) but it labels this level in the back-chevron
        // long-press history menu and for accessibility — without it those
        // entries render as empty glass pills.
        $bar = NavBar::make()
            ->back($isDocsReader)
            ->titleView(
                Row::make()->center()->gap(6)
                    ->addChild(
                        Icon::make('bolt.fill', ios: Ios::BoltFill, android: Android::Bolt)
                            ->size(20)
                            ->color(theme('primary'))
                    )
                    ->addChild(
                        Text::make($screen->navTitle())
                            ->italic()
                            ->fontSize(20)
                            ->fontWeight(7) // 1–7 scale; 7 = heaviest (iOS .heavy / Android ExtraBold)
                            ->color(theme('primary'))
                    )
            );

        // Top-bar gear → Settings, on the Scanner + Builds tabs (matches the
        // native app). A `url()` action navigates natively to the route.
        if (! $screen instanceof Settings) {
            //            $bar->action(
            //                NavAction::make('settings')
            //                    ->icon(ios: Ios::GearshapeFill, android: Android::Settings)
            //                    ->url('/settings')
            //                    ->a11yLabel('Settings')
            //            );
        }

        return $bar;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->activeColor(theme('primary'))
            ->add(Tab::link('Connect', '/', ios: Ios::WifiCircle, android: Android::Wifi))
            ->add(Tab::link('Docs', '/docs', ios: Ios::BookFill, android: Android::MenuBook))
            ->add(Tab::link('Videos', '/videos', ios: Ios::PlayCircleFill, android: Android::PlayCircle))
            // Docs search, available from every tab: results come from the
            // active screen's onSearchQuery() (SearchesDocs on all four tabs)
            // and each row navigates via the docs deep-link route.
            ->add(Tab::search('Search',
                placeholder: 'Search the docs…',
                ios: Ios::Magnifyingglass,
                android: Android::Search,
            ));
    }

    /**
     * The "N servers nearby" pill, floating above the tab bar on every tab.
     * Reads the app-wide store (fed by whichever tab is active via
     * {@see InteractsWithDiscovery}); renders
     * nothing when no servers are around. Tapping it opens the server-list
     * sheet — its `@press`/`$showServers` bindings resolve against the active
     * screen, which is why every tab uses the discovery trait.
     */
    public function floatingOverlay(NativeComponent $screen): ?FloatingOverlay
    {
        $store = app(DiscoveredServers::class);

        if ($store->isEmpty()) {
            return null;
        }

        return FloatingOverlay::make(
            view('native.discovery-pill', [
                'servers' => $store->all(),
                'serverCount' => $store->count(),
            ])
        )->offset(88);
    }
}
