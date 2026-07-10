<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full pb-32 pt-4">

        @if ($loading)
            <column class="w-full items-center justify-center gap-4 py-24">
                <text class="text-base text-theme-on-surface-variant">Loading videos…</text>
            </column>
        @elseif ($failed)
            <column class="w-full items-center justify-center gap-5 px-8 py-24">
                <native:icon :ios="App\Icons\Ios::WifiSlash" :android="App\Icons\Android::WifiOff" :size="40" color="#475569" dark-color="#94A3B8"/>
                <text class="text-base text-theme-on-surface-variant text-center">Unable to load videos. Check your connection.</text>
                <pressable @press="reload">
                    <row class="items-center px-6 py-3 bg-theme-primary rounded-xl">
                        <text class="text-sm font-bold text-theme-on-primary">Retry</text>
                    </row>
                </pressable>
            </column>
        @else

            {{-- Featured --}}
            @if ($featured)
                <pressable @press="watch('{{ $featured['url'] }}')" class="w-full px-5 pb-8">
                    <column class="w-full gap-3">
                        <column class="w-full h-[200] rounded-2xl bg-theme-surface overflow-hidden">
                            <image src="{{ $featured['thumb'] }}" fit="1" class="w-full h-full"/>
                        </column>
                        <row class="items-center gap-2">
                            <text class="text-xs font-bold text-theme-on-primary tracking-wider px-2 py-1 bg-theme-primary rounded-full">{{ $featured['category'] }}</text>
                            <text class="text-xs text-theme-on-surface-variant">{{ $featured['date'] }}</text>
                        </row>
                        <text class="text-xl font-bold text-theme-on-surface">{{ $featured['title'] }}</text>
                    </column>
                </pressable>
            @endif

            {{-- Section header --}}
            <row class="w-full items-center justify-between px-5 pb-4">
                <text class="text-xl font-bold text-theme-on-surface">All Videos</text>
                <text class="text-sm text-theme-on-surface-variant">{{ $count }} videos</text>
            </row>

            {{-- List --}}
            <column class="w-full px-5 gap-3">
                @foreach ($rest as $video)
                    <pressable @press="watch('{{ $video['url'] }}')">
                        <row class="w-full gap-3 p-2 bg-theme-surface rounded-2xl border border-theme-outline">
                            <column class="w-[120] h-[68] rounded-xl bg-theme-surface-variant overflow-hidden">
                                <image src="{{ $video['thumb'] }}" fit="1" class="w-full h-full"/>
                            </column>
                            <column class="flex-1 gap-1 py-1">
                                <text class="text-sm font-semibold text-theme-on-surface">{{ $video['title'] }}</text>
                                <row class="items-center gap-2">
                                    <text class="text-xs font-bold text-theme-primary tracking-wide">{{ $video['category'] }}</text>
                                    <text class="text-xs text-theme-on-surface-variant">{{ $video['date'] }}</text>
                                </row>
                            </column>
                        </row>
                    </pressable>
                @endforeach
            </column>

        @endif

    </column>
</scroll-view>
