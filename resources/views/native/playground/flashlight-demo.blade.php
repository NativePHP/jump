<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
        <text class="text-theme-on-surface font-semibold">Torch</text>
        <text class="text-theme-on-surface-variant text-sm">Device::flashlight() toggles the camera torch and reports the new state.</text>
    </column>

    <pressable @press="toggle" class="w-full">
        <column class="w-full p-10 gap-4 items-center rounded-3xl {{ $on ? 'bg-amber-400' : 'bg-theme-surface border border-theme-outline' }}">
            <icon ios="{{ $on ? 'flashlight.on.fill' : 'flashlight.off.fill' }}"
                  android="{{ $on ? 'flashlight_on' : 'flashlight_off' }}"
                  color="{{ $on ? '#78350F' : '#7C3AED' }}" :size="52"/>
            <text class="{{ $on ? 'text-amber-950' : 'text-theme-on-surface' }} text-xl font-bold">
                {{ $on ? 'On — tap to turn off' : 'Off — tap to turn on' }}
            </text>
        </column>
    </pressable>
</column>
