<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Haptics;

class HapticsDemo extends NativeComponent
{
    public int $buzzes = 0;

    public function navTitle(): string
    {
        return 'Haptics';
    }

    public function vibrate(): void
    {
        Haptics::vibrate();
        $this->buzzes++;
    }

    public function render(): View
    {
        return view('native.playground.haptics-demo');
    }
}
