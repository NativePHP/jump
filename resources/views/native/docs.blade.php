@if ($failed)
    <column class="w-full h-full items-center justify-center gap-5 px-8 bg-theme-background">
        <native:icon :ios="App\Icons\Ios::WifiSlash" :android="App\Icons\Android::WifiOff" :size="40" color="#475569" dark-color="#94A3B8"/>
        <text class="text-base text-theme-on-surface-variant text-center">Unable to load documentation. Check your connection.</text>
    </column>

@elseif ($page)
    {{-- ── Page reader ── --}}
    {{-- The reader is reached by a real navigation (TOC tap / search result /
         deep link all push the /docs/{...} route), so the native back chevron
         returns to the TOC — no in-content back bar needed. --}}
    <column class="w-full h-full bg-theme-background">
        {{-- Keyed by page id: prev/next flips the page in place (no
             navigation), so the scroll container needs a fresh native
             identity per page to reset the scroll offset to the top. --}}
        <refreshable @refresh="refresh" :native:key="$page['id']" class="w-full flex-1">
            <column class="w-full px-5 pb-32 gap-4">
                <column class="w-full items-start gap-3 pt-1">
                    {{-- Section banner — full-width, centered small-caps eyebrow. --}}
                    <row class="w-full justify-center px-4 py-2 bg-theme-surface-variant rounded-full">
                        <text class="text-sm font-bold text-theme-primary tracking-widest text-center">{{ strtoupper($page['section']) }}</text>
                    </row>
                    <text class="text-3xl font-black text-theme-on-surface">{{ $page['title'] }}</text>
                    @if ($page['description'])
                        <text class="text-base text-theme-on-surface-variant">{{ $page['description'] }}</text>
                    @endif
                </column>

                <column class="w-full gap-4 pt-2">
                    @foreach ($blocks as $bi => $b)
                        @if ($b['type'] === 'h2')
                            <text class="text-2xl font-bold text-theme-on-surface pt-2">{{ $b['text'] }}</text>
                        @elseif ($b['type'] === 'h3')
                            <text class="text-xl font-bold text-theme-on-surface pt-1">{{ $b['text'] }}</text>
                        @elseif ($b['type'] === 'li' && isset($b['segments']))
                            <row class="w-full items-start gap-2">
                                <text class="text-base text-theme-primary">•</text>
                                <text class="flex-1 text-base text-theme-on-surface-variant">@foreach ($b['segments'] as $seg)<text class="{{ $seg['code'] ? 'font-mono text-theme-primary bg-theme-surface-variant' : 'text-theme-on-surface-variant' }}">{{ $seg['t'] }}</text>@endforeach</text>
                            </row>
                        @elseif ($b['type'] === 'li')
                            <row class="w-full items-start gap-2">
                                <text class="text-base text-theme-primary">•</text>
                                <text class="flex-1 text-base text-theme-on-surface-variant">{{ $b['text'] }}</text>
                            </row>
                        @elseif ($b['type'] === 'p' && isset($b['segments']))
                            <text class="w-full text-base text-theme-on-surface-variant">@foreach ($b['segments'] as $seg)<text class="{{ $seg['code'] ? 'font-mono text-theme-primary bg-theme-surface-variant' : 'text-theme-on-surface-variant' }}">{{ $seg['t'] }}</text>@endforeach</text>
                        @elseif ($b['type'] === 'live')
                            {{-- Live example: the snippet's real native UI, rendered inline. --}}
                            <column class="w-full gap-2 pt-2">
                                <text class="text-xs font-bold text-theme-primary tracking-widest">PREVIEW</text>
                                {{-- `tall` (virtualized list) previews need a definite height —
                                     a SwiftUI List measured unbounded collapses to ~10pt. --}}
                                <column class="w-full p-4 bg-theme-surface rounded-xl border border-theme-outline {{ ($b['tall'] ?? false) ? 'h-[320]' : '' }}">
                                    @php ($renderLive)($b['snippet']) @endphp
                                </column>
                                {{-- source: fixed dark editor surface, per-line highlight, horizontal scroll.
                                     The copy chip is an `absolute` child of the column — FlexContainer pulls
                                     absolute children out of flow and pins them by inset (a <stack> would
                                     just center it over the code). --}}
                                <column class="w-full">
                                    <scroll-view horizontal class="w-full bg-slate-900 rounded-xl border border-slate-800">
                                        <column class="p-4">
                                            @foreach ($b['lines'] as $line)
                                                <text class="text-xs font-mono text-slate-200 select-text">@foreach ($line as $tk)<text class="{{ $tk['c'] }}">{{ $tk['t'] }}</text>@endforeach</text>
                                            @endforeach
                                        </column>
                                    </scroll-view>
                                    <pressable @press="copyCode({{ $bi }})" class="absolute top-2 right-2">
                                        <column class="p-2 bg-slate-800 rounded-lg">
                                            <native:icon :ios="App\Icons\Ios::DocOnDoc" :android="App\Icons\Android::ContentCopy" :size="14" color="#94A3B8"/>
                                        </column>
                                    </pressable>
                                </column>
                            </column>
                        @elseif ($b['type'] === 'code')
                            <column class="w-full">
                                <scroll-view horizontal class="w-full bg-slate-900 rounded-xl border border-slate-800">
                                    {{-- select-text on the ancestor → long-press selects the whole block. --}}
                                    <column class="p-4 select-text">
                                        @foreach ($b['lines'] as $line)
                                            <text class="text-xs font-mono text-slate-200 select-text">@foreach ($line as $tk)<text class="{{ $tk['c'] }}">{{ $tk['t'] }}</text>@endforeach</text>
                                        @endforeach
                                    </column>
                                </scroll-view>
                                <pressable @press="copyCode({{ $bi }})" class="absolute top-2 right-2">
                                    <column class="p-2 bg-slate-800 rounded-lg">
                                        <native:icon :ios="App\Icons\Ios::DocOnDoc" :android="App\Icons\Android::ContentCopy" :size="14" color="#94A3B8"/>
                                    </column>
                                </pressable>
                            </column>
                        @elseif ($b['type'] === 'callout')
                            @php
                                // [label, iconHex, bgTint, borderTint, titleColor, iosIcon, androidIcon].
                                // Alpha-tinted fills/borders read on both light and dark surfaces.
                                $cv = [
                                    'note'      => ['Note', '#3B82F6', 'bg-blue-500/10', 'border-blue-500/25', 'text-blue-500', App\Icons\Ios::InfoCircleFill, App\Icons\Android::Info],
                                    'tip'       => ['Tip', '#10B981', 'bg-emerald-500/10', 'border-emerald-500/25', 'text-emerald-500', App\Icons\Ios::LightbulbFill, App\Icons\Android::Lightbulb],
                                    'important' => ['Important', '#8B5CF6', 'bg-violet-500/10', 'border-violet-500/25', 'text-violet-500', App\Icons\Ios::ExclamationmarkCircleFill, App\Icons\Android::PriorityHigh],
                                    'warning'   => ['Warning', '#F59E0B', 'bg-amber-500/10', 'border-amber-500/25', 'text-amber-500', App\Icons\Ios::ExclamationmarkTriangleFill, App\Icons\Android::Warning],
                                    'caution'   => ['Caution', '#EF4444', 'bg-red-500/10', 'border-red-500/25', 'text-red-500', App\Icons\Ios::ExclamationmarkOctagonFill, App\Icons\Android::Dangerous],
                                ][$b['variant']] ?? ['Note', '#3B82F6', 'bg-blue-500/10', 'border-blue-500/25', 'text-blue-500', App\Icons\Ios::InfoCircleFill, App\Icons\Android::Info];
                            @endphp
                            <column class="w-full gap-2 p-4 rounded-xl border {{ $cv[2] }} {{ $cv[3] }}">
                                <row class="items-center gap-2">
                                    <native:icon :ios="$cv[5]" :android="$cv[6]" :size="15" color="{{ $cv[1] }}"/>
                                    <text class="text-xs font-bold tracking-widest {{ $cv[4] }}">{{ strtoupper($cv[0]) }}</text>
                                </row>
                                <text class="text-sm text-theme-on-surface">{{ $b['text'] }}</text>
                            </column>
                        @elseif ($b['type'] === 'table')
                            <column class="w-full rounded-xl border border-theme-outline">
                                <row class="w-full gap-3 px-4 py-3 items-start">
                                    @foreach ($b['header'] as $cell)
                                        <text class="flex-1 text-xs font-bold text-theme-on-surface tracking-wide">{{ strtoupper($cell['text'] ?? '') }}</text>
                                    @endforeach
                                </row>
                                @foreach ($b['rows'] as $cells)
                                    <column class="w-full h-[1] bg-theme-outline"/>
                                    <row class="w-full gap-3 px-4 py-3 items-start">
                                        @foreach ($cells as $cell)
                                            @if (isset($cell['segments']))
                                                <text class="flex-1 text-sm text-theme-on-surface-variant">@foreach ($cell['segments'] as $seg)<text class="{{ $seg['code'] ? 'font-mono text-theme-primary bg-theme-surface-variant' : 'text-theme-on-surface-variant' }}">{{ $seg['t'] }}</text>@endforeach</text>
                                            @else
                                                <text class="flex-1 text-sm text-theme-on-surface-variant">{{ $cell['text'] }}</text>
                                            @endif
                                        @endforeach
                                    </row>
                                @endforeach
                            </column>
                        @else
                            <text class="text-base text-theme-on-surface-variant">{{ $b['text'] }}</text>
                        @endif
                    @endforeach
                </column>

                {{-- Prev / next — nav-order neighbors (crossing sections),
                     like the website's footer links. --}}
                @if ($adjacent['prev'] || $adjacent['next'])
                    <row class="w-full gap-3 pt-4">
                        @if ($adjacent['prev'])
                            <pressable @press="goTo('{{ $adjacent['prev']['id'] }}')" class="flex-1">
                                <column class="w-full gap-1 p-4 rounded-xl border border-theme-outline items-start">
                                    <row class="items-center gap-1">
                                        <native:icon :ios="App\Icons\Ios::ChevronLeft" :android="App\Icons\Android::ChevronLeft" :size="10" color="#475569" dark-color="#94A3B8"/>
                                        <text class="text-xs text-theme-on-surface-variant">Previous</text>
                                    </row>
                                    <text class="text-sm font-semibold text-theme-primary">{{ $adjacent['prev']['title'] }}</text>
                                </column>
                            </pressable>
                        @else
                            <column class="flex-1"></column>
                        @endif
                        @if ($adjacent['next'])
                            <pressable @press="goTo('{{ $adjacent['next']['id'] }}')" class="flex-1">
                                <column class="w-full gap-1 p-4 rounded-xl border border-theme-outline items-end">
                                    <row class="items-center gap-1">
                                        <text class="text-xs text-theme-on-surface-variant">Next</text>
                                        <native:icon :ios="App\Icons\Ios::ChevronRight" :android="App\Icons\Android::ChevronRight" :size="10" color="#475569" dark-color="#94A3B8"/>
                                    </row>
                                    <text class="text-sm font-semibold text-theme-primary">{{ $adjacent['next']['title'] }}</text>
                                </column>
                            </pressable>
                        @else
                            <column class="flex-1"></column>
                        @endif
                    </row>
                @endif
            </column>
        </refreshable>
    </column>

@else
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
                        {{-- Real navigation to the page's deep-link route (not
                             open()-in-place): PHP's stack genuinely grows, so
                             the reader gets a native back chevron that pops in
                             sync. Same route search results and universal
                             links use. --}}
                        <pressable @press="navigate('/docs/{{ $p['id'] }}')">
                            <text class="w-full text-base text-theme-on-surface-variant px-3 py-2">{{ $p['title'] }}</text>
                        </pressable>
                    @endforeach
                @endif
            @endforeach
        </column>
    </refreshable>
@endif
