{{-- Home partial: see native.home for the per-width layouts that place it. --}}
<pressable @press="goVideos" class="{{ $class ?? 'flex-1' }}">
    <column
        class="w-full h-[190] p-4 bg-theme-surface rounded-3xl border border-theme-outline justify-between">
        <column class="gap-2">
            <text font="accent" class="text-base font-bold text-theme-on-surface">Video Guides</text>
            <text class="text-sm text-theme-on-surface-variant">Tutorials for building with Laravel on
                mobile.
            </text>
        </column>
        <row class="w-full items-center justify-between px-4 py-2 bg-theme-primary rounded-xl">
            <text class="text-sm font-bold text-theme-on-primary">Watch</text>
            <spacer/>
            <native:icon :size="20" :android="\App\Icons\Android::PlayCircleFill"
                         :ios="\App\Icons\Ios::PlayCircleFill" class="text-white"/>
        </row>
    </column>
</pressable>
