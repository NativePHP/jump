<?php

namespace App\NativeComponents\Layouts;

use App\Icons\Android;
use App\Icons\Ios;
use App\NativeComponents\Playground\Home;
use App\NativeComponents\Playground\MediaHub;
use App\NativeComponents\Playground\NotifyHub;
use App\NativeComponents\Playground\SystemHub;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * Chrome for the bundled NativePHP Playground (ported from the kitchensink
 * app). Entering /playground swaps Jump's tab bar for this one — deliberately
 * "a different app" — and the four hubs are the tab roots, with demo screens
 * pushing inside each tab's NavigationStack (their URIs prefix-match the tab).
 * Violet accent (vs Jump's indigo) reinforces that you've left the shell;
 * the way back is the "Exit to Jump" pill on the playground Home.
 */
class PlaygroundTabsLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        $isHub = in_array($screen::class, [Home::class, MediaHub::class, SystemHub::class, NotifyHub::class], true);

        return NavBar::make()
            ->back(! $isHub)
            ->displayMode($isHub ? 'large' : 'inline')
            ->title($screen->navTitle());
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->activeColor('#7C3AED')
            ->minimizeOnScroll()
            ->add(Tab::link('Home', '/playground', ios: Ios::HouseFill, android: Android::Home))
            ->add(Tab::link('Media', '/playground/media', ios: Ios::CameraFill, android: Android::PhotoCamera))
            ->add(Tab::link('System', '/playground/system', ios: Ios::GearshapeFill, android: Android::Settings))
            ->add(Tab::link('Notify', '/playground/notify', ios: Ios::BellFill, android: Android::Notifications));
    }
}
