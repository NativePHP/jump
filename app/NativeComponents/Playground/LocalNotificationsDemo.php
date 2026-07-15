<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;
use NativePHP\LocalNotifications\Events\NotificationTapped;
use NativePHP\LocalNotifications\Events\PermissionGranted;
use NativePHP\LocalNotifications\Facades\LocalNotifications;

class LocalNotificationsDemo extends NativeComponent
{
    public bool $permissionGranted = false;

    public string $lastTapInfo = '';

    /** @var array<int|string, mixed> */
    public array $scheduled = [];

    // Recurring reminder form
    public string $reminderTitle = 'Practice Time';

    public string $reminderBody = 'Time for your daily practice session!';

    public string $reminderTime = '09:00';

    public string $reminderFrequency = 'daily';

    public float $reminderWeekday = 1;

    public float $reminderDayOfMonth = 1;

    public function navTitle(): string
    {
        return 'Local Notifications';
    }

    public function mount(): void
    {
        $this->loadScheduled();
    }

    public function requestPermission(): void
    {
        // The released plugin (0.0.2) has no fluent permissionGranted()
        // callback yet — the result arrives via the PermissionGranted event.
        LocalNotifications::requestPermission();
    }

    #[On(PermissionGranted::class)]
    public function handlePermission(bool $granted): void
    {
        $this->permissionGranted = $granted;
        Dialog::toast($granted ? 'Notifications enabled!' : 'Permission denied');
    }

    // ── Immediate sends ────────────────────────────────────────────

    public function showSimple(): void
    {
        LocalNotifications::send('demo-simple')
            ->title('Hello!')
            ->sound('powerup')
            ->body('This is a simple local notification.');
    }

    public function showWithUrl(): void
    {
        LocalNotifications::send('demo-url')
            ->title('Tap to Navigate')
            ->sound('mario')
            ->body('This notification will take you to the haptics demo.')
            ->url('/system/haptics');
    }

    public function showWithActions(): void
    {
        LocalNotifications::send('demo-actions')
            ->title('New Friend Request')
            ->body('John wants to connect with you')
            ->subtitle('Social')
            ->url('/notify/local')
            ->data(['user_id' => 42])
            ->action('Accept', '/notify/local')
            ->action('Decline', '/notify/local', destructive: true)
            ->action('View Profile', '/system/device');
    }

    public function showDelayed(): void
    {
        LocalNotifications::send('demo-delayed')
            ->title('Scheduled Notification')
            ->body('This was scheduled 10 seconds ago!')
            ->url('/notify/local')
            ->delay(10);

        Dialog::toast('Arriving in 10 seconds…');
    }

    public function showSilent(): void
    {
        LocalNotifications::send('demo-silent')
            ->title('Silent Notification')
            ->body('This arrived without sound or vibration.')
            ->silent();

        Dialog::toast('Silent notification sent (check the shade)');
    }

    #[On(NotificationTapped::class)]
    public function handleTap(string $id, ?string $actionIdentifier = null, array $data = [], ?string $url = null): void
    {
        $parts = ["ID: {$id}"];

        if ($actionIdentifier) {
            $parts[] = "Action: {$actionIdentifier}";
        }

        if ($data !== []) {
            $parts[] = 'Data: '.json_encode($data);
        }

        if ($url) {
            $parts[] = "URL: {$url}";
        }

        $this->lastTapInfo = implode(' · ', $parts);
    }

    // ── Recurring reminder CRUD ────────────────────────────────────

    public function saveReminder(): void
    {
        $notification = LocalNotifications::schedule('user-reminder-'.md5($this->reminderTitle))
            ->title($this->reminderTitle)
            ->body($this->reminderBody)
            ->url('/notify/local');

        match ($this->reminderFrequency) {
            'hourly' => $notification->hourly(),
            'weekly' => $notification->weeklyOn((int) $this->reminderWeekday, $this->reminderTime),
            'monthly' => $notification->monthlyOn((int) $this->reminderDayOfMonth, $this->reminderTime),
            default => $notification->dailyAt($this->reminderTime),
        };

        // The builder auto-saves on __destruct once a frequency is set.
        unset($notification);

        Dialog::toast('Reminder saved!');
        $this->loadScheduled();
    }

    public function editReminder(string $id): void
    {
        $notification = LocalNotifications::edit($id);

        if (! $notification) {
            Dialog::toast('Reminder not found');

            return;
        }

        $this->reminderTitle = $notification->title;
        $this->reminderBody = $notification->body;
        $this->reminderFrequency = $notification->frequency ?? 'daily';

        if ($notification->at) {
            $this->reminderTime = $notification->at->format('H:i');
            $this->reminderWeekday = $notification->at->dayOfWeek;

            if ($notification->frequency === 'monthly') {
                $this->reminderDayOfMonth = $notification->at->day;
            }
        }

        Dialog::toast('Loaded — tweak and save');
    }

    public function removeReminder(string $id): void
    {
        LocalNotifications::remove($id);
        Dialog::toast('Reminder removed');
        $this->loadScheduled();
    }

    public function clearBadge(): void
    {
        LocalNotifications::clearBadge();
        Dialog::toast('Badge cleared');
    }

    public function cancelAll(): void
    {
        LocalNotifications::cancelAll();
        Dialog::toast('All notifications cancelled');
        $this->loadScheduled();
    }

    public function loadScheduled(): void
    {
        $this->scheduled = array_map(function (array $row) {
            $row['when'] = $this->describeSchedule($row);

            return $row;
        }, LocalNotifications::scheduled());
    }

    /**
     * Human summary from the scheduled_notifications columns
     * (frequency + hour/minute/weekday/day_of_month).
     *
     * @param  array<string, mixed>  $row
     */
    protected function describeSchedule(array $row): string
    {
        $time = sprintf('%02d:%02d', (int) ($row['hour'] ?? 0), (int) ($row['minute'] ?? 0));
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return match ($row['frequency'] ?? null) {
            'hourly' => 'Hourly at :'.sprintf('%02d', (int) ($row['minute'] ?? 0)),
            'daily' => "Daily at {$time}",
            'weekly' => ($weekdays[(int) ($row['weekday'] ?? 0)] ?? 'Weekly')." at {$time}",
            'monthly' => 'Monthly on day '.(int) ($row['day_of_month'] ?? 1)." at {$time}",
            'yearly' => "Yearly at {$time}",
            default => 'Once',
        };
    }

    public function render(): View
    {
        return view('native.playground.local-notifications-demo');
    }
}
