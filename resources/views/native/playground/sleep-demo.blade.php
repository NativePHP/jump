<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
        <text class="text-theme-on-surface font-semibold">Blocking vs queued work</text>
        <text class="text-theme-on-surface-variant text-sm">sleep(5) freezes the persistent PHP runtime; a queued job runs in the background while the UI stays interactive.</text>
    </column>

    <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
        <button @press="blockingSleep" variant="destructive" icon="zzz" class="w-full">Blocking sleep (5s)</button>
        <text class="text-theme-on-surface-variant text-sm">Watch the UI freeze — taps queue up until it wakes.</text>
    </column>

    <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
        <button @press="queuedSleep" icon="clock.arrow.circlepath" class="w-full">Queued sleep (5s)</button>
        <text class="text-theme-on-surface-variant text-sm">Dispatches a closure job — the toast arrives 5 seconds later without blocking.</text>
    </column>

    @if($status)
        <column class="w-full p-4 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface-variant">{{ $status }}</text>
        </column>
    @endif
</column>
