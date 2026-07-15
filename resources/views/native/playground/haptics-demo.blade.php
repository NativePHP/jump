<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
        <text class="text-theme-on-surface font-semibold">Haptic feedback</text>
        <text class="text-theme-on-surface-variant text-sm">Haptics::vibrate() fires the system haptic engine.</text>
    </column>

    <pressable @press="vibrate" ref="vibrate-card" class="w-full">
        <column class="w-full p-10 gap-4 items-center bg-theme-surface rounded-3xl border border-theme-outline">
            <stack class="w-20 h-20 rounded-full bg-pink-500 items-center justify-center">
                <icon ios="iphone.radiowaves.left.and.right" android="vibration" color="#FFFFFF" :size="34"/>
            </stack>
            <text class="text-theme-on-surface text-xl font-bold">Tap to vibrate</text>
            @if($buzzes)
                <badge :label="$buzzes.' buzz'.($buzzes === 1 ? '' : 'es')" variant="accent"/>
            @endif
        </column>
    </pressable>
</column>
