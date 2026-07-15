<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Audio recorder</text>
            <text class="text-theme-on-surface-variant text-sm">Microphone::record()->start() with pause / resume / stop. The finished file arrives via microphoneRecorded().</text>
        </column>

        {{-- Transport --}}
        @if($recordingState === 'idle')
            <button @press="startRecording" icon="mic.fill" class="w-full">Start recording</button>
        @else
            <column class="w-full p-4 gap-4 bg-theme-surface rounded-2xl border border-theme-outline">
                <row class="w-full gap-2 items-center">
                    @if($recordingState === 'recording')
                        <stack class="w-3 h-3 rounded-full bg-red-500"/>
                        <text class="text-theme-on-surface font-semibold">Recording…</text>
                    @else
                        <stack class="w-3 h-3 rounded-full bg-amber-500"/>
                        <text class="text-theme-on-surface font-semibold">Paused</text>
                    @endif
                </row>

                <row class="gap-3 w-full">
                    @if($recordingState === 'recording')
                        <button @press="pauseRecording" icon="pause.fill" class="flex-1">Pause</button>
                    @else
                        <button @press="resumeRecording" icon="play.fill" class="flex-1">Resume</button>
                    @endif
                    <button @press="stopRecording" variant="destructive" icon="stop.fill" class="flex-1">Stop</button>
                </row>
            </column>
        @endif

        @if($status)
            <column class="w-full p-4 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant">{{ $status }}</text>
            </column>
        @endif

        @if(count($recordings))
            <text class="text-theme-on-surface-variant text-sm font-semibold">RECORDINGS ({{ count($recordings) }})</text>

            <column class="w-full gap-3">
                @foreach($recordings as $recording)
                    <row :native:key="$recording['name']"
                         class="w-full p-4 gap-3 items-center bg-theme-surface rounded-2xl border border-theme-outline">
                        <pressable @press="playAudio('{{ $recording['name'] }}')">
                            <stack class="w-10 h-10 rounded-xl items-center justify-center {{ $playing === $recording['name'] ? 'bg-violet-600' : 'bg-emerald-500' }}">
                                <icon ios="{{ $playing === $recording['name'] ? 'stop.fill' : 'play.fill' }}"
                                      android="{{ $playing === $recording['name'] ? 'stop' : 'play_arrow' }}"
                                      color="#FFFFFF" :size="18"/>
                            </stack>
                        </pressable>
                        <column class="flex-1 gap-1">
                            <text class="text-theme-on-surface font-semibold" :maxLines="1">{{ $recording['name'] }}</text>
                            <text class="text-theme-on-surface-variant text-sm">
                                {{ $playing === $recording['name'] ? 'Playing…' : $recording['size'].' · '.$recording['date'] }}
                            </text>
                        </column>
                        <pressable @press="shareAudio('{{ $recording['name'] }}')">
                            <stack class="w-10 h-10 rounded-full bg-theme-surface-variant items-center justify-center">
                                <icon ios="square.and.arrow.up" android="share" color="#7C3AED" :size="17"/>
                            </stack>
                        </pressable>
                        <pressable @press="deleteAudio('{{ $recording['name'] }}')">
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
