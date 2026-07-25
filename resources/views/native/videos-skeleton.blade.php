{{-- #[Lazy] placeholder — static skeleton mirroring videos.blade.php
     (featured card, section header, list rows) so the frame doesn't shift
     when the feed lands. Bones are theme-surface-variant blocks; the
     renderer has no pulse animation, so they sit still. --}}
<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full pb-32 pt-4">

        {{-- Featured --}}
        <column class="w-full px-5 pb-8 gap-3">
            <column class="w-full h-[200] rounded-2xl bg-theme-surface-variant"/>
            <row class="items-center gap-2">
                <column class="w-[64] h-[22] rounded-full bg-theme-surface-variant"/>
                <column class="w-[48] h-[12] rounded-full bg-theme-surface-variant"/>
            </row>
            <column class="w-full h-[20] rounded-lg bg-theme-surface-variant"/>
            <column class="w-[180] h-[20] rounded-lg bg-theme-surface-variant"/>
        </column>

        {{-- Section header --}}
        <row class="w-full items-center justify-between px-5 pb-4">
            <column class="w-[110] h-[20] rounded-lg bg-theme-surface-variant"/>
            <column class="w-[60] h-[12] rounded-full bg-theme-surface-variant"/>
        </row>

        {{-- List rows --}}
        <column class="w-full px-5 gap-3">
            @foreach ([230, 150, 200, 170] as $w)
                <row class="w-full gap-3 p-2 bg-theme-surface rounded-2xl border border-theme-outline">
                    <column class="w-[120] h-[68] rounded-xl bg-theme-surface-variant"/>
                    <column class="flex-1 gap-2 py-2 items-start">
                        <column class="w-full h-[14] rounded-md bg-theme-surface-variant"/>
                        <column class="w-[{{ $w - 80 }}] h-[14] rounded-md bg-theme-surface-variant"/>
                        <column class="w-[90] h-[10] rounded-md bg-theme-surface-variant"/>
                    </column>
                </row>
            @endforeach
        </column>

    </column>
</scroll-view>
