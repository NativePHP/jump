<?php

namespace App\NativeComponents\Layouts;

use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * A plain native stack layout — a top bar with the screen's title and a native
 * back chevron. Used for pushed detail screens (e.g. Settings) reached from the
 * tabbed shell.
 */
class AppStackLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        return NavBar::make()
            ->back()
            ->title($screen->navTitle());
    }
}
