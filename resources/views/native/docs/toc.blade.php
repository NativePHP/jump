{{-- Docs table of contents. $select: open pages in place (split view) instead of pushing. --}}
{{-- ── Table of contents ── --}}
<refreshable @refresh="refresh" class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-3 pb-32">
        @foreach ($sections as $section)
            <pressable @press="toggle('{{ $section['slug'] }}')">
                <row class="w-full items-center py-3">
                    <text class="flex-1 text-sm font-bold text-theme-primary tracking-widest">{{ strtoupper($section['name']) }}</text>
                    <native:icon
                        :ios="in_array($section['slug'], $expanded, true) ? App\Icons\Ios::ChevronDown : App\Icons\Ios::ChevronRight"
                        :android="in_array($section['slug'], $expanded, true) ? App\Icons\Android::ExpandMore : App\Icons\Android::ChevronRight"
                        :size="12" class="text-red-600"/>
                </row>
            </pressable>

            @if (in_array($section['slug'], $expanded, true))
                @foreach ($section['pages'] as $p)
                    {{-- Narrow screens: a real navigation to the page's deep-link route
                         (not open()-in-place), so PHP's stack genuinely grows and the
                         reader gets a native back chevron that pops in sync — the same
                         route search results and universal links use. In the split view
                         ($select) the reader is beside the contents: open in place. --}}
                    @php($isCurrent = $select && ($page['id'] ?? null) === $p['id'])
                    {{-- The action's method name must be literal in the attribute (the
                         native precompiler reads it before Blade runs), so branch here. --}}
                    @if ($select)
                        <pressable @press="goTo('{{ $p['id'] }}')">
                            <text class="w-full text-base px-3 py-2 rounded-lg {{ $isCurrent ? 'text-theme-primary font-bold bg-theme-surface-variant' : 'text-theme-on-surface-variant' }}">{{ $p['title'] }}</text>
                        </pressable>
                    @else
                        <pressable @press="navigate('/docs/{{ $p['id'] }}')">
                            <text class="w-full text-base px-3 py-2 rounded-lg {{ $isCurrent ? 'text-theme-primary font-bold bg-theme-surface-variant' : 'text-theme-on-surface-variant' }}">{{ $p['title'] }}</text>
                        </pressable>
                    @endif
                @endforeach
            @endif
        @endforeach
    </column>
</refreshable>
