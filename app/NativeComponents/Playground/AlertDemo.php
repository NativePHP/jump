<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;

class AlertDemo extends NativeComponent
{
    public string $lastPressed = '';

    public function navTitle(): string
    {
        return 'Alert';
    }

    public function simpleAlert(): void
    {
        Dialog::alert('Hello!', 'A simple native alert with a single OK button.', ['OK'])
            ->buttonPressed(function ($event) {
                $this->lastPressed = "'{$event->label}' (index {$event->index})";
            });
    }

    public function confirmAlert(): void
    {
        Dialog::alert('Delete everything?', 'This is only a demo — nothing is actually deleted.', ['Cancel', 'Delete'])
            ->buttonPressed(function ($event) {
                $this->lastPressed = "'{$event->label}' (index {$event->index})";

                Dialog::toast($event->index === 1 ? 'Boom — deleted (not really)' : 'Phew, cancelled');
            });
    }

    public function threeButtonAlert(): void
    {
        Dialog::alert('Save changes?', 'Pick any of the three options.', ['Discard', 'Cancel', 'Save'])
            ->buttonPressed(function ($event) {
                $this->lastPressed = "'{$event->label}' (index {$event->index})";
            });
    }

    public function render(): View
    {
        return view('native.playground.alert-demo');
    }
}
