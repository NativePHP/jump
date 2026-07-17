<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-4">

        {{-- Hero --}}
        <column class="w-full gap-2 pt-2">
            <row class="items-center gap-2">
                <column class="w-[8] h-[8] rounded-full bg-violet-500" />
                <text class="text-xs font-bold uppercase text-violet-400 tracking-widest">NativePHP Playground</text>
            </row>
            <text class="text-3xl font-black text-theme-on-background">Laravel, running on your phone.</text>
            <text class="text-sm text-theme-on-surface-variant">
                Ten native APIs, one page. Each card shows the PHP you'd write, then runs that exact call on this device.
            </text>
        </column>

        {{-- 01 · Camera --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">01</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Camera</text>
                    <text class="text-sm text-theme-on-surface-variant">Open the system camera and get the photo back in PHP.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['camera'] }}</text>
            </column>
            <button @press="takePhoto" icon="camera.fill" size="sm" class="self-start">Take a photo</button>
            @if($cameraStatus)
                <text class="text-sm text-theme-on-surface-variant">{{ $cameraStatus }}</text>
            @endif
            @if(count($photos))
                <row class="w-full items-center justify-between">
                    <text class="text-xs font-semibold text-theme-on-surface-variant">CAPTURED ({{ count($photos) }})</text>
                    <button @press="clearPhotos" variant="ghost" size="sm">Clear</button>
                </row>
                <lazy-grid :columns="2" :gap="12" class="w-full">
                    @foreach($photos as $photo)
                        <image :native:key="$photo" :src="$photo"
                               alt="Captured photo {{ $loop->iteration }} of {{ count($photos) }}"
                               class="w-full aspect-square object-cover rounded-xl"/>
                    @endforeach
                </lazy-grid>
            @endif
        </column>

        {{-- 02 · Gallery --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">02</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Gallery</text>
                    <text class="text-sm text-theme-on-surface-variant">Pick images from the photo library with the system picker.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['gallery'] }}</text>
            </column>
            <button @press="pickImages" icon="photo.on.rectangle" size="sm" class="self-start">Open gallery</button>
            @if($galleryStatus)
                <text class="text-sm text-theme-on-surface-variant">{{ $galleryStatus }}</text>
            @endif
            @if(count($picked))
                <lazy-grid :columns="2" :gap="12" class="w-full">
                    @foreach($picked as $photo)
                        <image :native:key="$photo" :src="$photo"
                               alt="Selected image {{ $loop->iteration }} of {{ count($picked) }}"
                               class="w-full aspect-square object-cover rounded-xl"/>
                    @endforeach
                </lazy-grid>
            @endif
        </column>

        {{-- 03 · Video --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">03</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Video</text>
                    <text class="text-sm text-theme-on-surface-variant">Record up to 30 seconds and play it back inline.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['video'] }}</text>
            </column>
            <button @press="recordVideo" icon="video.fill" size="sm" class="self-start">Record video</button>
            @if($videoStatus)
                <text class="text-sm text-theme-on-surface-variant">{{ $videoStatus }}</text>
            @endif
            @if(count($videos))
                <video-player :native:key="'inline-'.$videos[0]['name']" :src="$videos[0]['path']"
                              controls class="w-full aspect-video rounded-xl"/>
                <row class="w-full items-center gap-3">
                    <column class="flex-1 gap-1">
                        <text class="text-theme-on-surface text-sm font-semibold" :maxLines="1">{{ $videos[0]['name'] }}</text>
                        <text class="text-theme-on-surface-variant text-xs">{{ $videos[0]['size'] }} · {{ $videos[0]['date'] }}</text>
                    </column>
                    <button @press="playVideo('{{ $videos[0]['name'] }}')" variant="ghost" size="sm">Full screen</button>
                    <button @press="deleteVideo('{{ $videos[0]['name'] }}')" variant="destructive" size="sm">Delete</button>
                </row>
            @endif
        </column>

        {{-- 04 · Location --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">04</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Location</text>
                    <text class="text-sm text-theme-on-surface-variant">Request permission, then read the current GPS position.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['location'] }}</text>
            </column>
            <row class="items-center gap-3">
                <button @press="getLocation" icon="location.fill" size="sm">Where am I?</button>
                @if($locationBlocked)
                    <button @press="openLocationSettings" icon="gearshape.fill" variant="ghost" size="sm">Open Settings</button>
                @endif
                @if($locating)
                    <activity-indicator/>
                @endif
            </row>
            @if($locationStatus)
                <text class="text-sm text-theme-on-surface-variant">{{ $locationStatus }}</text>
            @endif
            @if($latitude !== null)
                <column class="w-full p-3 gap-1 rounded-lg bg-theme-surface-variant">
                    <text class="text-sm font-mono text-theme-on-surface">{{ sprintf('%.5f, %.5f', $latitude, $longitude) }}</text>
                    <text class="text-xs text-theme-on-surface-variant">±{{ (int) ($accuracy ?? 0) }}m accuracy</text>
                </column>
            @endif
        </column>

        {{-- 05 · Notifications --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">05</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Notifications</text>
                    <text class="text-sm text-theme-on-surface-variant">Send local notifications — immediately or on a delay.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['notifications'] }}</text>
            </column>
            @if(! $notifyGranted)
                <button @press="requestNotifyPermission" icon="bell.badge.fill" size="sm" class="self-start">Request permission</button>
            @else
                <row class="items-center gap-2">
                    <icon ios="checkmark.circle.fill" android="check_circle" color="#10B981" :size="18"/>
                    <text class="text-sm text-theme-on-surface">Notifications enabled</text>
                </row>
            @endif
            <row class="gap-3">
                <button @press="sendNotification" icon="bell.fill" size="sm">Send now</button>
                <button @press="sendDelayedNotification" icon="clock.fill" size="sm">In 10 seconds</button>
            </row>
        </column>

        {{-- 06 · Biometrics --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">06</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Biometrics</text>
                    <text class="text-sm text-theme-on-surface-variant">Face ID / Touch ID on iOS, fingerprint on Android.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['biometrics'] }}</text>
            </column>
            <row class="w-full items-center gap-3">
                @if($bioState === 'unlocked')
                    <stack class="w-10 h-10 rounded-full bg-emerald-500 items-center justify-center">
                        <icon ios="lock.open.fill" android="lock_open" color="#FFFFFF" :size="18"/>
                    </stack>
                    <text class="flex-1 text-theme-on-surface font-semibold">Unlocked</text>
                    <button @press="lockAgain" variant="ghost" size="sm">Lock again</button>
                @elseif($bioState === 'failed')
                    <stack class="w-10 h-10 rounded-full bg-red-500 items-center justify-center">
                        <icon ios="xmark" android="close" color="#FFFFFF" :size="18"/>
                    </stack>
                    <text class="flex-1 text-theme-on-surface font-semibold">Failed — try again</text>
                    <button @press="authenticate" size="sm">Authenticate</button>
                @elseif($bioState === 'prompting')
                    <activity-indicator/>
                    <text class="flex-1 text-theme-on-surface-variant">Waiting for biometrics…</text>
                @else
                    <stack class="w-10 h-10 rounded-full bg-theme-surface-variant items-center justify-center">
                        <icon ios="lock.fill" android="lock" color="#7C3AED" :size="18"/>
                    </stack>
                    <text class="flex-1 text-theme-on-surface font-semibold">Locked</text>
                    <button @press="authenticate" icon="faceid" size="sm">Authenticate</button>
                @endif
            </row>
        </column>

        {{-- 07 · Device info --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">07</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Device info</text>
                    <text class="text-sm text-theme-on-surface-variant">Synchronous reads of hardware and battery state.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['device'] }}</text>
            </column>
            @if(count($deviceInfo) || count($battery))
                <column class="w-full gap-2">
                    @foreach($deviceInfo as $key => $value)
                        <row class="w-full justify-between">
                            <text class="text-sm text-theme-on-surface-variant capitalize">{{ str_replace('_', ' ', $key) }}</text>
                            <text class="text-sm text-theme-on-surface">{{ is_scalar($value) ? $value : json_encode($value) }}</text>
                        </row>
                    @endforeach
                    @foreach($battery as $key => $value)
                        <row class="w-full justify-between">
                            <text class="text-sm text-theme-on-surface-variant capitalize">{{ str_replace('_', ' ', $key) }}</text>
                            <text class="text-sm text-theme-on-surface">{{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $value : json_encode($value)) }}</text>
                        </row>
                    @endforeach
                </column>
            @else
                <text class="text-sm text-theme-on-surface-variant">Only available on a device.</text>
            @endif
            <button @press="readDevice" variant="ghost" size="sm" class="self-start">Refresh</button>
        </column>

        {{-- 08 · Flashlight --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">08</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Flashlight</text>
                    <text class="text-sm text-theme-on-surface-variant">Toggle the camera torch and read back the new state.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['flashlight'] }}</text>
            </column>
            <pressable @press="toggleTorch" class="w-full">
                <row class="w-full p-4 gap-3 items-center rounded-xl {{ $torchOn ? 'bg-amber-400' : 'bg-theme-surface-variant' }}">
                    <icon ios="{{ $torchOn ? 'flashlight.on.fill' : 'flashlight.off.fill' }}"
                          android="{{ $torchOn ? 'flashlight_on' : 'flashlight_off' }}"
                          color="{{ $torchOn ? '#78350F' : '#7C3AED' }}" :size="24"/>
                    <text class="{{ $torchOn ? 'text-amber-950' : 'text-theme-on-surface' }} font-semibold">
                        {{ $torchOn ? 'On — tap to turn off' : 'Off — tap to turn on' }}
                    </text>
                </row>
            </pressable>
        </column>

        {{-- 09 · Network --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">09</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Network</text>
                    <text class="text-sm text-theme-on-surface-variant">Connection state, type and cost hints.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['network'] }}</text>
            </column>
            @php($connected = (bool) ($network['connected'] ?? false))
            <row class="items-center gap-2">
                <icon ios="{{ $connected ? 'wifi' : 'wifi.slash' }}" android="{{ $connected ? 'wifi' : 'wifi_off' }}"
                      color="{{ $connected ? '#10B981' : '#EF4444' }}" :size="18"/>
                <badge :label="$connected ? 'Online' : 'Offline'" :variant="$connected ? 'primary' : 'destructive'"/>
            </row>
            @if(count($network))
                <column class="w-full gap-2">
                    @foreach($network as $key => $value)
                        <row class="w-full justify-between">
                            <text class="text-sm text-theme-on-surface-variant capitalize">{{ str_replace('_', ' ', \Illuminate\Support\Str::snake($key)) }}</text>
                            <text class="text-sm text-theme-on-surface">{{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $value : json_encode($value)) }}</text>
                        </row>
                    @endforeach
                </column>
            @else
                <text class="text-sm text-theme-on-surface-variant">Only available on a device.</text>
            @endif
            <button @press="readNetwork" variant="ghost" size="sm" class="self-start">Refresh</button>
        </column>

        {{-- 10 · Haptics --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full items-baseline gap-2">
                <text class="text-xs font-mono text-theme-outline">10</text>
                <column class="flex-1 gap-1">
                    <text class="text-base font-bold text-theme-on-surface">Haptics</text>
                    <text class="text-sm text-theme-on-surface-variant">Fire the system haptic engine.</text>
                </column>
            </row>
            <column class="w-full p-3 rounded-lg bg-theme-surface-variant">
                <text class="text-xs font-mono text-theme-on-surface-variant">{{ $code['haptics'] }}</text>
            </column>
            <pressable @press="vibrate" class="w-full">
                <row class="w-full p-4 gap-3 items-center bg-theme-surface-variant rounded-xl">
                    <icon ios="iphone.radiowaves.left.and.right" android="vibration" color="#EC4899" :size="24"/>
                    <text class="flex-1 text-theme-on-surface font-semibold">Tap to vibrate</text>
                    @if($buzzes)
                        <badge :label="$buzzes.' buzz'.($buzzes === 1 ? '' : 'es')" variant="accent"/>
                    @endif
                </row>
            </pressable>
        </column>

        {{-- Footer --}}
        <text class="text-xs text-theme-outline text-center pt-4 pb-8">
            PHP {{ PHP_VERSION }} · Laravel {{ app()->version() }}
        </text>

    </column>
</scroll-view>
