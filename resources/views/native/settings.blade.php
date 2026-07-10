<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 py-5 gap-6">


        {{-- ── NOTIFICATIONS ── --}}
        <column class="w-full gap-3">
            <text class="text-sm font-semibold text-theme-on-surface-variant tracking-wide">NOTIFICATIONS</text>
            <column class="w-full bg-theme-surface rounded-xl border border-theme-outline">
                <row class="w-full items-center gap-3 px-4 py-4">
                    <column class="flex-1 gap-1">
                        <text class="text-base text-theme-on-surface">Push Notifications</text>
                        <text class="text-xs text-theme-on-surface-variant">Build updates and alerts</text>
                    </column>
                    <toggle :value="$pushEnabled" @change="togglePush"/>
                </row>
                <column class="w-full h-[1] bg-theme-outline"/>
                <row class="w-full items-center gap-3 px-4 py-4">
                    <column class="flex-1 gap-1">
                        <text class="text-base text-theme-on-surface">Marketing &amp; Announcements</text>
                        <text class="text-xs text-theme-on-surface-variant">Product updates and news</text>
                    </column>
                    <toggle :value="$marketingEnabled" @change="toggleMarketing"/>
                </row>
            </column>
        </column>

        {{-- ── ABOUT ── --}}
        <column class="w-full gap-3">
            <text class="text-sm font-semibold text-theme-on-surface-variant tracking-wide">ABOUT</text>
            <column class="w-full bg-theme-surface rounded-xl border border-theme-outline">
                <row class="w-full items-center px-4 py-4">
                    <text class="flex-1 text-base text-theme-on-surface">Version</text>
                    <text class="text-base text-theme-on-surface-variant">{{ $version }}</text>
                </row>
            </column>
        </column>

    </column>
</scroll-view>
