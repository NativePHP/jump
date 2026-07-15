<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Video recorder</text>
            <text class="text-theme-on-surface-variant text-sm">Camera::recordVideo() opens the system recorder. Recordings land in app storage — swipe a row to share or delete.</text>
        </column>

        <column class="w-full p-4 gap-1 bg-theme-surface rounded-2xl border border-theme-outline">
            <text class="text-theme-on-surface-variant text-sm">Max duration: {{ (int) $maxDuration }}s</text>
            <slider native:model.blur="maxDuration" :min="5" :max="120" :step="5" a11y-label="Max duration in seconds" class="w-full"/>
        </column>

        <button @press="recordVideo" icon="video.fill" class="w-full">Record video</button>

        @if($status)
            <column class="w-full p-4 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant">{{ $status }}</text>
            </column>
        @endif

        @if(count($videos))
            {{-- Inline native player (the <video-player> element) showing the latest recording --}}
            <text class="text-theme-on-surface-variant text-sm font-semibold">LATEST — INLINE PLAYER</text>
            <video-player :native:key="'inline-'.$videos[0]['name']" :src="$videos[0]['path']"
                          controls class="w-full aspect-video rounded-2xl"/>

            <text class="text-theme-on-surface-variant text-sm font-semibold">RECORDINGS ({{ count($videos) }})</text>

            <column class="w-full gap-3">
                @foreach($videos as $video)
                    <row :native:key="$video['name']"
                         class="w-full p-4 gap-3 items-center bg-theme-surface rounded-2xl border border-theme-outline">
                        <pressable @press="playVideo('{{ $video['name'] }}')">
                            <stack class="w-10 h-10 rounded-xl bg-pink-500 items-center justify-center">
                                <icon ios="play.fill" android="play_arrow" color="#FFFFFF" :size="18"/>
                            </stack>
                        </pressable>
                        <column class="flex-1 gap-1">
                            <text class="text-theme-on-surface font-semibold" :maxLines="1">{{ $video['name'] }}</text>
                            <text class="text-theme-on-surface-variant text-sm">{{ $video['size'] }} · {{ $video['date'] }}</text>
                        </column>
                        <pressable @press="shareVideo('{{ $video['name'] }}')">
                            <stack class="w-10 h-10 rounded-full bg-theme-surface-variant items-center justify-center">
                                <icon ios="square.and.arrow.up" android="share" color="#7C3AED" :size="17"/>
                            </stack>
                        </pressable>
                        <pressable @press="deleteVideo('{{ $video['name'] }}')">
                            <stack class="w-10 h-10 rounded-full bg-theme-surface-variant items-center justify-center">
                                <icon ios="trash" android="delete" color="#EF4444" :size="17"/>
                            </stack>
                        </pressable>
                    </row>
                @endforeach
            </column>
        @endif
    </column>
</scroll-view>
