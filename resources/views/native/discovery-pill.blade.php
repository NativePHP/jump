{{--
    App-wide "N servers nearby" pill, floated above the tab bar by
    JumpTabsLayout::floatingOverlay(). $servers / $serverCount come from the
    layout; $showServers is the active screen's own state (merged in from its
    public properties), so @press resolves against the screen — every tab uses
    the InteractsWithDiscovery trait.
--}}
<column class="items-center">
    @if ($showPill)
        <pressable @press="toggleServers">
            <row class="items-center gap-2 px-5 py-3 bg-theme-primary rounded-full shadow-lg">
                <native:icon :ios="App\Icons\Ios::Wifi" :android="App\Icons\Android::Wifi" :size="16" color="#FFFFFF"/>
                <text class="text-sm font-semibold text-theme-on-primary">{{ $serverCount === 1 ? '1 server nearby' : $serverCount.' servers nearby' }}</text>
            </row>
        </pressable>
    @endif

    <bottom-sheet :visible="$showServers" @dismiss="closeServers" detents="medium">
        <column class="w-full px-5 pt-5 pb-8 gap-1">
            <text class="text-lg font-bold text-theme-on-surface pb-2">On your network</text>
            @foreach ($servers as $server)
                <pressable @press="connect('{{ $server['host'] }}', '{{ $server['port'] }}')">
                    <row class="w-full items-center gap-3 py-3">
                        <native:icon :ios="App\Icons\Ios::Wifi" :android="App\Icons\Android::Wifi" :size="18" class="text-red-600"/>
                        <column class="flex-1 gap-1">
                            <text class="text-base font-medium text-theme-on-surface">{{ $server['name'] }}</text>
                            <text class="text-xs font-mono text-theme-on-surface-variant">{{ $server['host'] }}:{{ $server['port'] }}</text>
                        </column>
                        <native:icon :ios="App\Icons\Ios::ArrowRight" :android="App\Icons\Android::ArrowForward" :size="14" class="text-red-600"/>
                    </row>
                </pressable>
            @endforeach
        </column>
    </bottom-sheet>

    {{--
        Connecting cover. Full-screen (iOS presents <modal> as a
        fullScreenCover) so Jump's UI is hidden for the second or two between
        tapping a server and the remote app's first frame.

        Not just polish: the remote app pushes its own theme as it boots, and
        its font aliases name files Jump doesn't bundle — so home would
        visibly re-render in fallback fonts while it waits. Every colour here
        is a literal and there are no `font=` tokens, so the incoming theme
        can't move this screen either.

        Not dismissible: the escape-hatch gesture is the way out, which is
        exactly what the sheet below has just taught.
    --}}
    <modal :visible="$connecting" :dismissible="false">
        <column class="w-full h-full items-center justify-center gap-6 px-10 bg-white dark:bg-slate-950">
            <native:activity-indicator :size="36" color="#DC2626"/>
            <column class="w-full items-center gap-2">
                <text class="text-lg font-bold text-center text-slate-900 dark:text-slate-100">
                    @if ($connectingTo !== '')
                        Connecting to {{ $connectingTo }}
                    @else
                        Connecting
                    @endif
                </text>
                <text class="text-sm text-center text-slate-500 dark:text-slate-400">Starting the app on your
                    phone — swipe right with three fingers any time to come back.
                </text>
            </column>
        </column>
    </modal>

    {{--
        Escape-hatch coaching sheet. Interjected by
        InteractsWithDiscovery::connect() before every Jump until the user
        opts out via the checkbox (flag kept in the cache table / device
        SQLite) — once connected, the remote app owns the whole screen, so
        the exit gesture must be taught BEFORE handoff.
    --}}
    <bottom-sheet :visible="$showExitHint" @dismiss="dismissExitHint" detents="medium">
        <column class="w-full px-6 pt-6 pb-10 gap-5">
            <text font="accent" class="text-2xl font-black text-theme-on-surface">Before you Jump</text>
            <text class="text-base text-theme-on-surface-variant">The app you're connecting to will take over this screen — here's how to get back:</text>

            <row class="w-full items-center gap-4 py-4 px-5 bg-theme-surface-variant rounded-2xl">
                <native:icon :ios="App\Icons\Ios::HandDraw" :android="App\Icons\Android::Gesture" :size="34" class="text-red-600"/>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Swipe right with three fingers</text>
                    <text class="text-sm text-theme-on-surface-variant">Anywhere on the screen, any time — you'll land right back here.</text>
                </column>
                <native:icon :ios="App\Icons\Ios::ArrowRight" :android="App\Icons\Android::ArrowForward" :size="20" class="text-red-600"/>
            </row>

            <checkbox :value="$dontShowExitHintAgain" label="Don't show this again" @change="setDontShowExitHintAgain"/>

            <pressable @press="connectPending">
                <row class="w-full items-center justify-center py-4 bg-theme-primary rounded-full">
                    <text font="accent" class="text-base font-bold text-theme-on-primary">Got it — Jump in</text>
                </row>
            </pressable>
        </column>
    </bottom-sheet>
</column>
