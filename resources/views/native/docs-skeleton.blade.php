{{-- #[Lazy] placeholder — static skeleton mirroring the docs TOC (first
     section expanded with page rows, the rest collapsed) so the frame
     doesn't shift when the corpus lands. Varied bar widths read as text. --}}
<column class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-3 pb-32">

        {{-- Expanded first section: header + page rows --}}
        <row class="w-full items-center py-3">
            <column class="w-[150] h-[13] rounded-full bg-theme-surface-variant"/>
            <spacer/>
            <column class="w-[12] h-[12] rounded-md bg-theme-surface-variant"/>
        </row>
        @foreach ([210, 150, 240, 180, 120] as $w)
            <column class="w-full items-start px-3 py-2">
                <column class="w-[{{ $w }}] h-[16] rounded-md bg-theme-surface-variant"/>
            </column>
        @endforeach

        {{-- Collapsed section headers --}}
        @foreach ([120, 170, 100, 140, 190, 110] as $w)
            <row class="w-full items-center py-3">
                <column class="w-[{{ $w }}] h-[13] rounded-full bg-theme-surface-variant"/>
                <spacer/>
                <column class="w-[12] h-[12] rounded-md bg-theme-surface-variant"/>
            </row>
        @endforeach

    </column>
</column>
