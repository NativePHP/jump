<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Concerns\SearchesDocs;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * Builds tab — Bifrost cloud builds. Without a signed-in Bifrost account this
 * shows the "Ship with Bifrost" sign-in prompt (the app's not-logged-in state).
 */
class Builds extends NativeComponent
{
    use InteractsWithDiscovery;
    use SearchesDocs;

    public function navTitle(): string
    {
        return 'Bifrost Builds';
    }

    public function mount(): void
    {
        $this->startDiscovery();
    }

    public function openBifrost(): void
    {
        Browser::open('https://bifrost.nativephp.com');
    }

    public function render(): View
    {
        return view('native.builds');
    }
}
