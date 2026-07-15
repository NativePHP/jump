<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Biometrics;

class BiometricsDemo extends NativeComponent
{
    public string $state = 'locked';

    public function navTitle(): string
    {
        return 'Biometrics';
    }

    public function authenticate(): void
    {
        $this->state = 'prompting';

        Biometrics::prompt()
            ->completed(function ($event) {
                $this->state = $event->success ? 'unlocked' : 'failed';
            });
    }

    public function reset(): void
    {
        $this->state = 'locked';
    }

    public function render(): View
    {
        return view('native.playground.biometrics-demo');
    }
}
