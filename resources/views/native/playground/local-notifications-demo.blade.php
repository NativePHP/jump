<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-3 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Local notifications</text>
            <text class="text-theme-on-surface-variant text-sm">Immediate, delayed, silent and recurring notifications — with sounds, deep-link URLs, payload data and action buttons.</text>
        </column>

        @if(! $permissionGranted)
            <button @press="requestPermission" icon="bell.badge.fill" class="w-full">Request permission</button>
        @else
            <row class="w-full items-center gap-2 p-3 bg-theme-surface rounded-2xl border border-theme-outline">
                <icon ios="checkmark.circle.fill" android="check_circle" color="#10B981" :size="20"/>
                <text class="text-theme-on-surface">Notifications enabled</text>
            </row>
        @endif

        {{-- Send demos --}}
        <text class="text-theme-on-surface-variant text-sm font-semibold">SEND</text>
        <column class="w-full gap-3">
            <button @press="showSimple" icon="bell.fill" class="w-full">Simple notification</button>
            <button @press="showWithUrl" icon="link" class="w-full">Deep link → haptics demo</button>
            <button @press="showWithActions" icon="rectangle.and.hand.point.up.left.fill" class="w-full">Action buttons + data</button>
            <button @press="showDelayed" icon="clock.fill" class="w-full">Delayed by 10 seconds</button>
            <button @press="showSilent" icon="bell.slash.fill" class="w-full">Silent notification</button>
        </column>

        @if($lastTapInfo)
            <column class="w-full p-4 gap-1 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">LAST TAP</text>
                <text class="text-theme-on-surface text-sm font-mono">{{ $lastTapInfo }}</text>
            </column>
        @endif

        {{-- Recurring reminders --}}
        <text class="text-theme-on-surface-variant text-sm font-semibold">RECURRING REMINDER</text>
        <column class="w-full p-4 gap-4 bg-theme-surface rounded-2xl border border-theme-outline">
            <outlined-text-input label="Title" native:model.blur="reminderTitle" class="w-full"/>
            <outlined-text-input label="Body" native:model.blur="reminderBody" class="w-full"/>
            <select label="Frequency" native:model="reminderFrequency"
                    :options="['hourly', 'daily', 'weekly', 'monthly']" class="w-full"/>

            @if($reminderFrequency !== 'hourly')
                <outlined-text-input label="Time (HH:MM)" native:model.blur="reminderTime" class="w-full"/>
            @endif

            @if($reminderFrequency === 'weekly')
                <column class="gap-1">
                    <text class="text-theme-on-surface-variant text-sm">Weekday (0 = Sunday): {{ (int) $reminderWeekday }}</text>
                    <slider native:model.blur="reminderWeekday" :min="0" :max="6" :step="1" class="w-full"/>
                </column>
            @endif

            @if($reminderFrequency === 'monthly')
                <column class="gap-1">
                    <text class="text-theme-on-surface-variant text-sm">Day of month: {{ (int) $reminderDayOfMonth }}</text>
                    <slider native:model.blur="reminderDayOfMonth" :min="1" :max="28" :step="1" class="w-full"/>
                </column>
            @endif

            <button @press="saveReminder" icon="calendar.badge.plus" class="w-full">Save reminder</button>
        </column>

        @if(count($scheduled))
            <text class="text-theme-on-surface-variant text-sm font-semibold">SCHEDULED ({{ count($scheduled) }})</text>

            <column class="w-full gap-3">
                @foreach($scheduled as $notification)
                    <column :native:key="$notification['id'] ?? $loop->index"
                            class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
                        <row class="w-full gap-3 items-center">
                            <stack class="w-10 h-10 rounded-xl bg-indigo-500 items-center justify-center">
                                <icon ios="calendar" android="event_repeat" color="#FFFFFF" :size="18"/>
                            </stack>
                            <column class="flex-1 gap-1">
                                <text class="text-theme-on-surface font-semibold">{{ $notification['title'] ?? 'Untitled' }}</text>
                                <text class="text-theme-on-surface-variant text-sm">{{ $notification['when'] }}</text>
                                @if(!empty($notification['body']))
                                    <text class="text-theme-on-surface-variant text-sm" :maxLines="2">{{ $notification['body'] }}</text>
                                @endif
                            </column>
                        </row>
                        <row class="gap-3 w-full">
                            <button @press="editReminder('{{ $notification['id'] ?? '' }}')" size="sm" class="flex-1">Edit</button>
                            <button @press="removeReminder('{{ $notification['id'] ?? '' }}')" size="sm" variant="destructive" class="flex-1">Remove</button>
                        </row>
                    </column>
                @endforeach
            </column>
        @endif

        {{-- Maintenance --}}
        <row class="gap-3 w-full">
            <button @press="clearBadge" class="flex-1">Clear badge</button>
            <button @press="cancelAll" variant="destructive" class="flex-1">Cancel all</button>
        </row>
    </column>
</scroll-view>
