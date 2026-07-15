<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Facades\Scanner;

class ScannerDemo extends NativeComponent
{
    /** Growing list screen — keep PHP and native trees in lockstep. */
    protected bool $forceFullFrames = true;

    public string $format = 'all';

    public ?string $lastData = null;

    public ?string $lastFormat = null;

    /** @var array<int, array{data: string, format: string, time: string}> */
    public array $scans = [];

    public bool $continuousActive = false;

    public function navTitle(): string
    {
        return 'Scanner';
    }

    public function scanOnce(): void
    {
        $this->continuousActive = false;

        Scanner::make()
            ->prompt('Scan a code')
            ->formats([$this->format])
            ->codeScanned(function ($event) {
                $this->lastData = $event->data;
                $this->lastFormat = $event->format;
            })
            ->scannerCancelled(function () {
                $this->lastData = null;
                $this->lastFormat = 'cancelled';
            })
            ->scan();
    }

    public function scanContinuously(): void
    {
        $this->continuousActive = true;

        // Fluent callbacks are one-shot; a continuous session fires many
        // CodeScanned events, so those flow through the #[On] listener below.
        Scanner::make()
            ->prompt('Scan codes continuously')
            ->formats([$this->format])
            ->continuous()
            ->scan();
    }

    #[On(CodeScanned::class)]
    public function handleScanned(string $data, string $format): void
    {
        if (! $this->continuousActive) {
            return;
        }

        $this->scans[] = [
            'data' => $data,
            'format' => $format,
            'time' => now()->format('H:i:s'),
        ];
    }

    public function clearScans(): void
    {
        $this->scans = [];
        $this->lastData = null;
        $this->lastFormat = null;
        $this->continuousActive = false;
    }

    public function render(): View
    {
        return view('native.playground.scanner-demo');
    }
}
