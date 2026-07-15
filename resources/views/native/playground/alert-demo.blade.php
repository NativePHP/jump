<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-5">

        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Native alerts</text>
            <text class="text-theme-on-surface-variant text-sm">Dialog::alert() shows a real system alert. Which button was tapped comes back through buttonPressed().</text>
        </column>

        <column class="w-full gap-3">
            @foreach([
                ['method' => 'simpleAlert', 'ios' => '1.circle.fill', 'android' => 'looks_one', 'color' => '#7C3AED', 'title' => 'Simple alert', 'subtitle' => 'One OK button'],
                ['method' => 'confirmAlert', 'ios' => 'exclamationmark.triangle.fill', 'android' => 'warning', 'color' => '#EF4444', 'title' => 'Confirmation', 'subtitle' => 'Cancel / Delete pair'],
                ['method' => 'threeButtonAlert', 'ios' => '3.circle.fill', 'android' => 'looks_3', 'color' => '#0EA5E9', 'title' => 'Three buttons', 'subtitle' => 'Discard / Cancel / Save'],
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

        @if($lastPressed)
            <column class="w-full p-4 gap-1 bg-theme-surface rounded-2xl border border-theme-outline">
                <text class="text-theme-on-surface-variant text-sm font-semibold">LAST BUTTON</text>
                <text class="text-theme-on-surface">{{ $lastPressed }}</text>
            </column>
        @endif
    </column>
</scroll-view>
