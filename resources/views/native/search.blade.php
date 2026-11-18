<column class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-4 pb-3">
        <native:bare-text-input
            native:model.debounce.300ms="query"
            placeholder="Search the docs…"
            class="w-full px-4 py-3 bg-theme-surface rounded-xl border border-theme-outline text-base text-theme-on-surface"/>
    </column>

    <scroll-view class="w-full flex-1">
        <column class="w-full px-5 pb-8 gap-2">
            @forelse ($results as $result)
                <pressable @press="open('{{ $result['url'] }}')" class="w-full">
                    <column class="w-full gap-1 p-4 bg-theme-surface rounded-2xl border border-theme-outline">
                        <text class="text-base font-bold text-theme-on-surface">{{ $result['title'] }}</text>
                        <text class="text-sm text-theme-on-surface-variant">{{ $result['subtitle'] }}</text>
                    </column>
                </pressable>
            @empty
                <column class="w-full items-center pt-8">
                    @if ($query === '')
                        <skia-canvas :scene="App\Support\JumpSkia::idle()" animated canvas-id="search-idle"
                                     a11y-label="" class="w-[120] h-[90]"/>
                    @endif
                    <text class="text-sm text-theme-on-surface-variant text-center">
                        {{ $query === '' ? 'Type to search the NativePHP docs.' : 'No matches.' }}
                    </text>
                </column>
            @endforelse
        </column>
    </scroll-view>
</column>
