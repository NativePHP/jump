<refreshable @refresh="refresh" class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Connectivity</text>
            <text class="text-theme-on-surface-variant text-sm">Network::status() reports connection, type and cost hints. Pull to refresh.</text>
        </column>

        @php($connected = (bool) ($status['connected'] ?? false))

        <column class="w-full p-6 gap-3 items-center bg-theme-surface rounded-3xl border border-theme-outline">
            <stack class="w-16 h-16 rounded-full items-center justify-center {{ $connected ? 'bg-emerald-500' : 'bg-red-500' }}">
                <icon ios="{{ $connected ? 'wifi' : 'wifi.slash' }}" android="{{ $connected ? 'wifi' : 'wifi_off' }}" color="#FFFFFF" :size="28"/>
            </stack>
            <badge :label="$connected ? 'Online' : 'Offline'" :variant="$connected ? 'primary' : 'destructive'"/>
        </column>

        @if(count($status))
            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">DETAILS</text>
                @foreach($status as $key => $value)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant capitalize">{{ str_replace('_', ' ', \Illuminate\Support\Str::snake($key)) }}</text>
                        <text class="text-theme-on-surface">{{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $value : json_encode($value)) }}</text>
                    </row>
                @endforeach
            </column>
        @else
            <column class="w-full p-4 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant">Status is only available on a device.</text>
            </column>
        @endif
    </column>
</refreshable>
