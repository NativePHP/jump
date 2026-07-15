<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;

class SleepDemo extends NativeComponent
{
    public string $status = '';

    public function navTitle(): string
    {
        return 'Sleep vs Queue';
    }

    public function blockingSleep(): void
    {
        Dialog::toast('Sleeping for 5 seconds — the UI is blocked…');

        sleep(5);

        Dialog::toast('Done! That blocked every dispatch.');
        $this->status = 'Blocking sleep finished at '.now()->format('H:i:s');
    }

    public function queuedSleep(): void
    {
        dispatch(function () {
            sleep(5);
            Dialog::toast('Queued job finished sleeping!');
        });

        $this->status = 'Job queued at '.now()->format('H:i:s').' — the UI stayed responsive.';
        Dialog::toast('Job queued — UI stays free.');
    }

    public function render(): View
    {
        return view('native.playground.sleep-demo');
    }
}
