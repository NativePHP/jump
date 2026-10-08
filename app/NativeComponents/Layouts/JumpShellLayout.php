<?php

namespace App\NativeComponents\Layouts;

use App\Icons\Android;
use App\Icons\Ios;
use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Docs;
use App\NativeComponents\Home;
use App\NativeComponents\Search;
use App\NativeComponents\Videos;
use App\Support\DiscoveredServers;
use Native\Mobile\Edge\Element;
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
 * The Jump app's root chrome on a native STACK (the Curbshot approach) rather
 * than a TabView. The stack renderer paints the window background
 * (`UI.SetBackground`) through the safe areas and extends the bottom bar's own
 * background under the home indicator, so the theme color fills the whole
 * screen — the TabView path leaves those bands on the platform default.
 *
 * Tabs are core's bottom-nav element drawn in the stack's bottomBar() slot.
 */
class JumpShellLayout extends NativeLayout
{
    use HasFloatingOverlay;

    /** [label, uri, screen class, iOS symbol, Android symbol] */
    private const TABS = [
        ['Connect', '/', Home::class, Ios::WifiCircle, Android::Wifi],
        ['Docs', '/docs', Docs::class, Ios::BookFill, Android::MenuBook],
        ['Videos', '/videos', Videos::class, Ios::PlayCircleFill, Android::PlayCircle],
        ['Search', '/search', Search::class, Ios::Magnifyingglass, Android::Search],
    ];

    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        $isDocsReader = $screen instanceof Docs && $screen->page !== null;

        // Explicit background → opaque bar in the theme color, painted up
        // through the status bar (same as Curbshot's top bar).
        return NavBar::make()
            ->back($isDocsReader)
            ->backgroundColor(theme('background'))
            ->textColor(theme('on-background'))
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
                            ->fontWeight(7)
                            ->color(theme('primary'))
                    )
            )
            ->action(
                NavAction::make('settings')
                    ->icon(ios: Ios::GearshapeFill, android: Android::Settings)
                    ->url('/settings')
                    ->a11yLabel('Settings')
            );
    }

    public function bottomBar(NativeComponent $screen): ?Element
    {
        // Pushed docs reader gets the full screen, like a TabView hiding its
        // bar on push.
        if ($screen instanceof Docs && $screen->page !== null) {
            return null;
        }

        // Core's <native:bottom-nav>, drawn in the stack's bottom-bar slot
        // instead of hoisted into a TabView. Items with a url() replace the
        // current screen on tap (no push); the matching screen is active.
        $bar = TabBar::make()
            ->activeColor(theme('primary'))
            ->backgroundColor(theme('background'))
            ->textColor(theme('on-surface-variant'));

        foreach (self::TABS as [$label, $uri, $class, $ios, $android]) {
            $bar->add(Tab::link($label, $uri, ios: $ios, android: $android)->active($screen instanceof $class));
        }

        return $bar->toElement();
    }

    /**
     * The "N servers nearby" pill — same as the tabs layout, see
     * {@see InteractsWithDiscovery}.
     */
    public function floatingOverlay(NativeComponent $screen): ?FloatingOverlay
    {
        $store = app(DiscoveredServers::class);
        $exitHintOpen = (bool) ($screen->showExitHint ?? false);

        if ($store->isEmpty() && ! $exitHintOpen) {
            return null;
        }

        return FloatingOverlay::make(
            view('native.discovery-pill', [
                'servers' => $store->all(),
                'serverCount' => $store->count(),
                'showPill' => ! $store->isEmpty(),
            ])
        )->offset(88);
    }
}
