<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Barcode & QR scanner</text>
            <text class="text-theme-on-surface-variant text-sm">Single scans resolve through the one-shot codeScanned() callback. Continuous mode streams every hit through an #[On(CodeScanned)] listener.</text>
        </column>

        <select label="Format" native:model="format"
                :options="['all', 'qr', 'ean13', 'ean8', 'code128', 'upca']"
                class="w-full"/>

        <row class="gap-3 w-full">
            <button @press="scanOnce" icon="qrcode.viewfinder" class="flex-1">Scan once</button>
            <button @press="scanContinuously" icon="infinity" class="flex-1">Continuous</button>
        </row>

        @if($lastData || $lastFormat === 'cancelled')
            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">LAST SCAN</text>
                @if($lastFormat === 'cancelled')
                    <text class="text-theme-on-surface-variant">Scan cancelled</text>
                @else
                    <text class="text-theme-on-surface font-mono">{{ $lastData }}</text>
                    <chip :label="$lastFormat" :selected="true"/>
                @endif
            </column>
        @endif

        @if(count($scans))
            <row class="w-full items-center justify-between">
                <text class="text-theme-on-surface-variant text-sm font-semibold">HISTORY ({{ count($scans) }})</text>
                <button @press="clearScans" variant="ghost" size="sm">Clear</button>
            </row>

            <column class="w-full gap-3">
                @foreach($scans as $i => $scan)
                    <row :native:key="$i.'-'.$scan['time']"
                         class="w-full p-4 gap-3 items-center bg-theme-surface rounded-2xl border border-theme-outline">
                        <stack class="w-10 h-10 rounded-xl bg-sky-500 items-center justify-center">
                            <icon ios="barcode.viewfinder" android="qr_code_scanner" color="#FFFFFF" :size="18"/>
                        </stack>
                        <column class="flex-1 gap-1">
                            <text class="text-theme-on-surface font-mono" :maxLines="2">{{ $scan['data'] }}</text>
                            <text class="text-theme-on-surface-variant text-sm">{{ $scan['format'] }} · {{ $scan['time'] }}</text>
                        </column>
                    </row>
                @endforeach
            </column>
        @endif
    </column>
</scroll-view>
