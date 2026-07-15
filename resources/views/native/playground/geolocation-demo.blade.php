<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Location services</text>
            <text class="text-theme-on-surface-variant text-sm">One-shot position and permission calls use fluent
                callbacks; the live watch streams fixes through a persistent locationUpdated() listener.
            </text>
        </column>

        {{-- Permissions --}}
        <text class="text-theme-on-surface-variant text-sm font-semibold">PERMISSIONS</text>
        <column class="gap-3 w-full">
            <button @press="checkPermissions" icon="checkmark.shield" class="w-full">Check permissions
            </button>
            <button @press="requestPermissions" icon="location.viewfinder" class="w-full">Request
                permissions
            </button>
        </column>

        @if(count($permissions))
            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">PERMISSIONS</text>
                @foreach($permissions as $name => $value)
                    <row class="w-full items-center justify-between">
                        <text class="text-theme-on-surface capitalize">{{ $name }}</text>
                        <chip :label="$value" :selected="$value === 'granted'"/>
                    </row>
                @endforeach
            </column>
        @endif

        @if($status)
            <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant">{{ $status }}</text>
                <button @press="openSettings" class="w-full">Open Settings</button>
            </column>
        @endif

        {{-- One-shot position --}}
        <button @press="getCurrentPosition" icon="location.fill" :loading="$locating" ref="get-position"
                class="w-full">Get current position
        </button>

        @if($latitude !== null)
            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">POSITION</text>
                <row class="w-full justify-between">
                    <text class="text-theme-on-surface-variant">Latitude</text>
                    <text class="text-theme-on-surface font-mono">{{ number_format($latitude, 6) }}</text>
                </row>
                <row class="w-full justify-between">
                    <text class="text-theme-on-surface-variant">Longitude</text>
                    <text class="text-theme-on-surface font-mono">{{ number_format($longitude, 6) }}</text>
                </row>
                <row class="w-full justify-between">
                    <text class="text-theme-on-surface-variant">Accuracy</text>
                    <text class="text-theme-on-surface font-mono">±{{ number_format($accuracy, 1) }} m</text>
                </row>
                @if($provider)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Provider</text>
                        <text class="text-theme-on-surface">{{ $provider }}</text>
                    </row>
                @endif
            </column>
        @endif

        {{-- Streaming watch --}}
        <text class="text-theme-on-surface-variant text-sm font-semibold">LIVE STREAMING</text>

        @if($watchId === null)
            <button @press="startWatch" icon="location.north.line.fill" class="w-full">Start watching
                position
            </button>
        @else
            <button @press="stopWatch" variant="destructive" icon="stop.fill" class="w-full">Stop watching
            </button>

            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <row class="w-full items-center justify-between">
                    <row class="gap-2 items-center">
                        <activity-indicator size="sm"/>
                        <text class="text-theme-on-surface font-semibold">Streaming…</text>
                    </row>
                    <badge :label="$watchUpdates.' fix'.($watchUpdates === 1 ? '' : 'es')" variant="primary"/>
                </row>

                @if($watchLatitude !== null)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Latitude</text>
                        <text class="text-theme-on-surface font-mono">{{ number_format($watchLatitude, 6) }}</text>
                    </row>
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Longitude</text>
                        <text class="text-theme-on-surface font-mono">{{ number_format($watchLongitude, 6) }}</text>
                    </row>
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Accuracy</text>
                        <text class="text-theme-on-surface font-mono">±{{ number_format($watchAccuracy, 1) }} m</text>
                    </row>
                @else
                    <text class="text-theme-on-surface-variant text-sm">Waiting for the first fix… (simulator: Features
                        → Location)
                    </text>
                @endif
            </column>
        @endif

        {{-- Background watch --}}
        <text class="text-theme-on-surface-variant text-sm font-semibold">BACKGROUND WATCH</text>

        @if($bgWatchId === null)
            <column class="gap-3 w-full">
                <button @press="startBackgroundWatch" icon="location.circle.fill" class="w-full">Start
                    background watch
                </button>
                <text class="text-theme-on-surface-variant text-sm">Keeps recording after you leave this screen,
                    background the app, kill it, or reboot. Fixes buffer natively and are drained when you come back.
                </text>
            </column>
        @else
            <column class="w-full p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                <row class="w-full items-center justify-between">
                    <row class="gap-2 items-center">
                        <activity-indicator size="sm"/>
                        <text class="text-theme-on-surface font-semibold">Recording in background</text>
                    </row>
                    <badge :label="'live '.$bgLiveUpdates" variant="primary"/>
                </row>

                <row class="w-full justify-between">
                    <text class="text-theme-on-surface-variant">Drained from buffer</text>
                    <text class="text-theme-on-surface font-mono">{{ $bgDrained }} fixes</text>
                </row>
                <row class="w-full justify-between">
                    <text class="text-theme-on-surface-variant">Buffer size</text>
                    <text class="text-theme-on-surface font-mono">{{ $bgBufferBytes !== null ? number_format($bgBufferBytes).' B' : '—' }}</text>
                </row>
                @if($bgAuthorization !== null)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Authorization</text>
                        <text class="text-theme-on-surface font-mono">{{ $bgAuthorization }}</text>
                    </row>
                @endif
                @if($bgBackgroundMode !== null)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Background mode</text>
                        <text class="text-theme-on-surface font-mono">{{ $bgBackgroundMode }}</text>
                    </row>
                @endif
                @if($bgGates !== null)
                    <text class="text-sm font-semibold" color="#DC2626">⚠ Blocking background delivery: {{ $bgGates }}</text>
                @endif
                @if($bgLatitude !== null)
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Last fix</text>
                        <text class="text-theme-on-surface font-mono">{{ number_format($bgLatitude, 5) }}
                            , {{ number_format($bgLongitude, 5) }}</text>
                    </row>
                    <row class="w-full justify-between">
                        <text class="text-theme-on-surface-variant">Speed</text>
                        <text class="text-theme-on-surface font-mono">
                            {{ $bgSpeed !== null ? number_format($bgSpeed, 1).' m/s' : '—' }}</text>
                    </row>
                @endif
                {{-- Native map: keyless (MapKit on iOS, MapLibre + OpenFreeMap on Android).
                     The polyline takes drained fixes directly; camera follows the last
                     fix because lat/lng change on each drain. --}}
                @if(count($fixes) > 1)
                    <toggle label="Follow my position" native:model="bgFollow" class="w-full"/>

                    <row class="w-full items-center gap-3">
                        <text class="text-theme-on-surface-variant text-sm">Zoom</text>
                        <slider native:model.live="bgZoom" :min="10" :max="19" :step="1" class="flex-1"/>
                        <text class="text-theme-on-surface font-mono text-sm">{{ (int) $bgZoom }}</text>
                    </row>

                    <native:map class="w-full h-72 rounded-2xl"
                         :lat="$bgLatitude" :lng="$bgLongitude" :zoom="$bgZoom"
                         :follow="$bgFollow"
                         :polyline="$this->trackFixes()"
                         polyline-color="#6d32a8" polyline-width="7"
                         :show-user-location="false">
                        @if($bgLatitude !== null)
                            <native:map-marker :lat="$bgLatitude" :lng="$bgLongitude"
                                        title="Current" color="#6d32a8"
                                        @press="drainBackgroundBuffer"/>
                        @endif
                    </native:map>
                @endif

                <text class="text-theme-on-surface-variant text-sm">Navigate away and come back — the watch keeps
                    running and mount() re-attaches and drains.
                </text>

{{--                <button @press="drainBackgroundBuffer" icon="arrow.down.circle" class="w-full">Drain buffer now</button>--}}
                <button @press="stopBackgroundWatch" variant="destructive" icon="stop.fill" class="w-full">Stop
                    background watch
                </button>
            </column>
        @endif
    </column>
</scroll-view>
