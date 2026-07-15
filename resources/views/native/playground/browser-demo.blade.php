<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Three ways to browse</text>
            <text class="text-theme-on-surface-variant text-sm">In-app opens SFSafariViewController / Custom Tabs, auth opens an ephemeral session for OAuth, system hands off to the default browser.</text>
        </column>

        <outlined-text-input label="URL" keyboard="url" native:model.blur="url" class="w-full"/>

        <column class="w-full gap-3">
            @foreach([
                ['method' => 'openInApp', 'ios' => 'rectangle.inset.filled', 'android' => 'open_in_new', 'color' => '#7C3AED', 'title' => 'In-app browser', 'subtitle' => 'Stays inside the app'],
                ['method' => 'openAuth', 'ios' => 'person.badge.key.fill', 'android' => 'key', 'color' => '#10B981', 'title' => 'Auth session', 'subtitle' => 'Ephemeral session for OAuth flows'],
                ['method' => 'openSystem', 'ios' => 'safari.fill', 'android' => 'public', 'color' => '#0EA5E9', 'title' => 'System browser', 'subtitle' => 'Opens Safari / Chrome'],
            ] as $option)
                <pressable :native:key="$option['method']" @press="{{ $option['method'] }}">
                    <row class="w-full p-4 gap-3 items-center bg-theme-surface rounded-2xl border border-theme-outline">
                        <stack :bg="$option['color']" class="w-10 h-10 rounded-xl items-center justify-center">
                            <icon :ios="$option['ios']" :android="$option['android']" color="#FFFFFF" :size="18"/>
                        </stack>
                        <column class="flex-1 gap-1">
                            <text class="text-theme-on-surface font-semibold">{{ $option['title'] }}</text>
                            <text class="text-theme-on-surface-variant text-sm">{{ $option['subtitle'] }}</text>
                        </column>
                        <icon ios="chevron.right" android="chevron_right" color="#A29AB2" :size="16"/>
                    </row>
                </pressable>
            @endforeach
        </column>
    </column>
</scroll-view>
