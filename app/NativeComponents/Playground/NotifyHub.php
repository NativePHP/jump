<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class NotifyHub extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Notify';
    }

    /** @return array<int, array{title: string, demos: array<int, array<string, string>>}> */
    public function groups(): array
    {
        // The kitchensink push/data-messages demos are omitted: they need the
        // firebase plugin (and a Firebase project baked into Jump's build).
        return [
            [
                'title' => 'Notifications',
                'demos' => [
                    ['id' => 'local', 'title' => 'Local Notifications', 'subtitle' => 'Send now, delay, schedule recurring reminders', 'icon' => 'bell.badge.fill', 'color' => '#7C3AED', 'url' => '/playground/notify/local'],
                ],
            ],
            [
                'title' => 'Dialogs & Sheets',
                'demos' => [
                    ['id' => 'alert', 'title' => 'Alert', 'subtitle' => 'Native alerts with button callbacks', 'icon' => 'exclamationmark.bubble.fill', 'color' => '#EF4444', 'url' => '/playground/notify/alert'],
                    ['id' => 'toast', 'title' => 'Toast', 'subtitle' => 'Transient toast messages', 'icon' => 'text.bubble.fill', 'color' => '#F59E0B', 'url' => '/playground/notify/toast'],
                    ['id' => 'share', 'title' => 'Share Sheet', 'subtitle' => 'System share sheet for links and text', 'icon' => 'square.and.arrow.up.fill', 'color' => '#0EA5E9', 'url' => '/playground/notify/share'],
                ],
            ],
        ];
    }

    public function render(): View
    {
        return view('native.playground.hub', ['groups' => $this->groups()]);
    }
}
