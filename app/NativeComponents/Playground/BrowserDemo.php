<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

class BrowserDemo extends NativeComponent
{
    public string $url = 'https://nativephp.com';

    public function navTitle(): string
    {
        return 'Browser';
    }

    public function openInApp(): void
    {
        Browser::inApp($this->url);
    }

    public function openAuth(): void
    {
        Browser::auth($this->url);
    }

    public function openSystem(): void
    {
        Browser::open($this->url);
    }

    public function render(): View
    {
        return view('native.playground.browser-demo');
    }
}
