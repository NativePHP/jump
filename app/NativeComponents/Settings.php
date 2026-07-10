<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * Settings — replicates the native Jump app's SettingsTab: Account (Bifrost
 * sign-in), Notifications (two toggles), and About (version). Reached via the
 * gear in the top bar; pushed with native back chrome.
 */
class Settings extends NativeComponent
{
    public bool $pushEnabled = true;

    public bool $marketingEnabled = true;

    public function navTitle(): string
    {
        return 'Settings';
    }

    public function togglePush(bool $value): void
    {
        $this->pushEnabled = $value;
    }

    public function toggleMarketing(bool $value): void
    {
        $this->marketingEnabled = $value;
    }

    public function signIn(): void
    {
        Browser::open('https://bifrost.nativephp.com');
    }

    public function render(): View
    {
        return view('native.settings', [
            'version' => (string) config('nativephp.version', '1.0'),
        ]);
    }
}
