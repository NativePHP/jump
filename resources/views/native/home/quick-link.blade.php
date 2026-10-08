{{-- Home partial: a compact link row for Docs / Videos where the tall
     quick-access tiles would sit alone in a column (see native.home). --}}
<pressable @press="{{ $press }}" class="w-full">
    <row class="w-full items-center gap-3 p-4 bg-theme-surface rounded-2xl border border-theme-outline">
        <column class="w-[44] h-[44] rounded-xl bg-theme-surface-variant items-center justify-center">
            <native:icon :ios="$ios" :android="$android" :size="22" class="text-theme-primary"/>
        </column>
        <column class="flex-1 gap-1">
            <text font="accent" class="text-base font-bold text-theme-on-surface">{{ $title }}</text>
            <text class="text-sm text-theme-on-surface-variant">{{ $text }}</text>
        </column>
        <native:icon :ios="App\Icons\Ios::ArrowRight" :android="App\Icons\Android::ArrowForward" :size="14"
                     class="text-red-500"/>
    </row>
</pressable>
