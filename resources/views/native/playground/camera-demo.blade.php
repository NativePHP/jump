<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Native camera</text>
            <text class="text-theme-on-surface-variant text-sm">Camera::getPhoto() opens the system camera. The photo path comes back through the photoTaken() callback.</text>
        </column>

        <button @press="takePhoto" icon="camera.fill" class="w-full">Take a photo</button>

        @if($status)
            <column class="w-full p-4 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant">{{ $status }}</text>
            </column>
        @endif

        @if(count($photos))
            <row class="w-full items-center justify-between">
                <text class="text-theme-on-surface-variant text-sm font-semibold">CAPTURED ({{ count($photos) }})</text>
                <button @press="clearPhotos" variant="ghost" size="sm">Clear</button>
            </row>

            <lazy-grid :columns="2" :gap="12" class="w-full">
                @foreach($photos as $photo)
                    <image :native:key="$photo" :src="$photo"
                           alt="Captured photo {{ $loop->iteration }} of {{ count($photos) }}"
                           class="w-full aspect-square object-cover rounded-2xl shadow"/>
                @endforeach
            </lazy-grid>
        @endif
    </column>
</scroll-view>
