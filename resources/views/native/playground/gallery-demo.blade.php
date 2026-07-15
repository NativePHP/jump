<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Photo library</text>
            <text class="text-theme-on-surface-variant text-sm">Camera::pickImages() opens the system picker. mediaSelected() reports the chosen files — cancellation comes back in-band on the same event.</text>
        </column>

        {{-- Options --}}
        <column class="w-full p-4 gap-4 bg-theme-surface rounded-2xl border border-theme-outline">
            <text class="text-theme-on-surface-variant text-sm font-semibold">OPTIONS</text>
            <select label="Media type" native:model="mediaType" :options="['images', 'videos', 'all']" class="w-full"/>
            <toggle label="Allow multiple" native:model="multiple" class="w-full"/>
            <column class="gap-1">
                <text class="text-theme-on-surface-variant text-sm">Max items: {{ (int) $maxItems }}</text>
                <slider native:model.live="maxItems" :min="1" :max="10" :step="1" a11y-label="Max items" class="w-full"/>
            </column>
        </column>

        <button @press="openGallery" icon="photo.on.rectangle" class="w-full">Open gallery</button>

        @if($status)
            <column class="w-full p-4 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant">{{ $status }}</text>
            </column>
        @endif

        @if(count($photos))
            <text class="text-theme-on-surface-variant text-sm font-semibold">SELECTED ({{ count($photos) }})</text>
            <lazy-grid :columns="2" :gap="12" class="w-full">
                @foreach($photos as $photo)
                    <image :native:key="$photo" :src="$photo"
                           alt="Selected media {{ $loop->iteration }} of {{ count($photos) }}"
                           class="w-full aspect-square object-cover rounded-2xl shadow"/>
                @endforeach
            </lazy-grid>
        @endif
    </column>
</scroll-view>
