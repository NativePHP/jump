<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;

class ShareDemo extends NativeComponent
{
    public string $title = 'NativePHP';

    public string $text = 'Build native mobile apps with PHP!';

    public string $url = 'https://nativephp.com';

    public function navTitle(): string
    {
        return 'Share';
    }

    public function share(): void
    {
        Dialog::share($this->title, $this->text, $this->url);
    }

    public function render(): View
    {
        return view('native.playground.share-demo');
    }
}
