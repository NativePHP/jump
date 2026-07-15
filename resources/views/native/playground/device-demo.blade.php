<refreshable @refresh="refresh" class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Device</text>
            <text class="text-theme-on-surface-variant text-sm">Device::getId(), getInfo() and getBatteryInfo() are synchronous calls. Pull to refresh.</text>
        </column>

        <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
            <text class="text-theme-on-surface-variant text-sm font-semibold">IDENTIFIER</text>
            <text class="text-theme-on-surface font-mono text-sm">{{ $deviceId ?? 'Only available on device' }}</text>
        </column>

        @if(count($info))
            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">HARDWARE</text>
                @foreach($info as $key => $value)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant capitalize">{{ str_replace('_', ' ', $key) }}</text>
                        <text class="text-theme-on-surface">{{ is_scalar($value) ? $value : json_encode($value) }}</text>
                    </row>
                @endforeach
            </column>
        @endif

        @if(count($battery))
            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">BATTERY</text>
                @if(isset($battery['level']))
                    <progress-bar :value="(float) $battery['level']" class="w-full"/>
                @endif
                @foreach($battery as $key => $value)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant capitalize">{{ str_replace('_', ' ', $key) }}</text>
                        <text class="text-theme-on-surface">{{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $value : json_encode($value)) }}</text>
                    </row>
                @endforeach
            </column>
        @endif
    </column>
</refreshable>
