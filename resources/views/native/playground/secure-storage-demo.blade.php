<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Keychain / Keystore</text>
            <text class="text-theme-on-surface-variant text-sm">SecureStorage::set/get/delete persists values in the iOS Keychain and Android Keystore.</text>
        </column>

        <column class="w-full p-4 gap-4 bg-theme-surface rounded-2xl border border-theme-outline">
            <outlined-text-input label="Key" placeholder="e.g. api_token" native:model.blur="key" class="w-full"/>
            @nativeError('key')

            <outlined-text-input label="Value" placeholder="Secret value" native:model.blur="value" class="w-full"/>
            @nativeError('value')

            <column class="gap-3 w-full">
                <button @press="store"  class="w-full">Store</button>
                <button @press="retrieve" class="w-full" >Retrieve</button>
                <button @press="forget" class="w-full" variant="destructive" >Delete</button>
            </column>
        </column>

        @if($retrieved !== null)
            <column class="w-full p-4 gap-1 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">RETRIEVED VALUE</text>
                <text class="text-theme-on-surface font-mono">{{ $retrieved }}</text>
            </column>
        @endif
    </column>
</scroll-view>
