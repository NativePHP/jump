{{-- Home partial: see native.home for the per-width layouts that place it. --}}
<pressable @press="playground" class="{{ $class ?? 'w-full' }}">
    <row class="w-full h-full items-start gap-3 p-5 bg-theme-surface rounded-3xl border border-theme-outline">
        <column class="w-[44] h-[44] rounded-xl bg-theme-surface-variant items-center justify-center">
            <native:icon :ios="App\Icons\Ios::Sparkles" :android="App\Icons\Android::AutoAwesome" :size="22"
                         class="text-red-600"/>
        </column>
        <column class="flex-1 gap-1">
            <text font="accent" class="text-sm lg:text-xl font-bold text-theme-on-surface">SuperNative Playground</text>
            <text class="text-sm text-theme-on-surface-variant">A bundled Laravel app that demonstrates 20
                native APIs — camera, share, scanner, secure storage, and more.
            </text>
        </column>
        <native:icon :ios="App\Icons\Ios::ArrowRight" :android="App\Icons\Android::ArrowForward" :size="14"
                     class="text-red-500"/>
    </row>
</pressable>
