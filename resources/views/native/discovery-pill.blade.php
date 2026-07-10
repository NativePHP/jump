{{--
    App-wide "N servers nearby" pill, floated above the tab bar by
    JumpTabsLayout::floatingOverlay(). $servers / $serverCount come from the
    layout; $showServers is the active screen's own state (merged in from its
    public properties), so @press resolves against the screen — every tab uses
    the InteractsWithDiscovery trait.
--}}
<column class="items-center">
    <pressable @press="toggleServers">
        <row class="items-center gap-2 px-5 py-3 bg-theme-primary rounded-full shadow-lg">
            <native:icon :ios="App\Icons\Ios::Wifi" :android="App\Icons\Android::Wifi" :size="16" color="#FFFFFF"/>
            <text class="text-sm font-semibold text-theme-on-primary">{{ $serverCount === 1 ? '1 server nearby' : $serverCount.' servers nearby' }}</text>
        </row>
    </pressable>

    <bottom-sheet :visible="$showServers" @dismiss="closeServers" detents="medium">
        <column class="w-full px-5 pt-5 pb-8 gap-1">
            <text class="text-lg font-bold text-theme-on-surface pb-2">On your network</text>
            @foreach ($servers as $server)
                <pressable @press="connect('{{ $server['host'] }}', '{{ $server['port'] }}')">
                    <row class="w-full items-center gap-3 py-3">
                        <native:icon :ios="App\Icons\Ios::Wifi" :android="App\Icons\Android::Wifi" :size="18" color="#4F46E5"/>
                        <column class="flex-1 gap-1">
                            <text class="text-base font-medium text-theme-on-surface">{{ $server['name'] }}</text>
                            <text class="text-xs font-mono text-theme-on-surface-variant">{{ $server['host'] }}:{{ $server['port'] }}</text>
                        </column>
                        <native:icon :ios="App\Icons\Ios::ArrowRight" :android="App\Icons\Android::ArrowForward" :size="14" color="#4F46E5"/>
                    </row>
                </pressable>
            @endforeach
        </column>
    </bottom-sheet>
</column>
