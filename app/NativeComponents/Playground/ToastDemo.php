<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;

class ToastDemo extends NativeComponent
{
    public string $message = 'Hello from NativePHP!';

    public string $duration = 'long';

    public function navTitle(): string
    {
        return 'Toast';
    }

    public function showToast(): void
    {
        if (trim($this->message) === '') {
            return;
        }

        Dialog::toast($this->message, $this->duration);
    }

    public function render(): View
    {
        return view('native.playground.toast-demo');
    }
}
