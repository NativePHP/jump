<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Network;

class NetworkDemo extends NativeComponent
{
    /** @var array<string, mixed> */
    public array $status = [];

    public function navTitle(): string
    {
        return 'Network';
    }

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->status = (array) (Network::status() ?? []);
    }

    public function render(): View
    {
        return view('native.playground.network-demo');
    }
}
