<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full px-5 pt-2 pb-10 gap-6">

        {{-- Exit — the playground swaps in its own tab chrome, so there is no
             native back chevron out of it; this is the way home to Jump. --}}
        <pressable @press="exitPlayground" class="self-start">
            <row class="items-center gap-2 px-4 py-2 bg-theme-surface-variant rounded-full border border-theme-outline">
                <icon ios="chevron.left" android="arrow_back" :size="12" color="#4F46E5"/>
                <text class="text-sm font-semibold text-theme-primary">Exit to Jump</text>
            </row>
        </pressable>

        {{-- Hero --}}
        <stack class="w-full bg-violet-600 dark:bg-violet-800 rounded-3xl shadow-xl">
            <column class="w-full h-full items-end justify-end p-4">
                <icon ios="square.grid.2x2.fill" android="widgets" color="#FFFFFF" size="170" class="opacity-10"/>
            </column>

            <column class="w-full p-6 py-8 gap-3">
                <text class="text-white/60 text-sm font-semibold">NATIVEPHP FOR MOBILE</text>
                <text class="text-white text-3xl font-extrabold">Playground</text>
                <text class="text-white/80 text-lg">Every native API, rendered as real native UI. No webview.</text>

                <row class="gap-3 mt-4">
                    <stack @press="navigate('/playground/media')" class="glass:clear android:bg-white/20 rounded-2xl px-5 py-3 items-center justify-center">
                        <text class="text-white font-semibold">Explore Media</text>
                    </stack>
                    <stack @press="navigate('/playground/system')" class="glass:clear android:bg-white/20 rounded-2xl px-5 py-3 items-center justify-center">
                        <text class="text-white font-semibold">System APIs</text>
                    </stack>
                </row>
            </column>
        </stack>

        {{-- Featured demos --}}
        <text class="text-theme-on-surface-variant text-sm font-semibold">FEATURED</text>
        <scroll-view horizontal :shows-indicators="false" class="w-full">
            <row class="gap-4">
                @foreach($featured as $demo)
                    <pressable :native:key="$demo['id']" @press="navigate('{{ $demo['url'] }}')">
                        <column class="w-36 p-4 gap-2 bg-theme-surface rounded-2xl border border-theme-outline">
                            <stack :bg="$demo['color']" class="w-11 h-11 rounded-xl items-center justify-center">
                                <icon :ios="$demo['ios']" :android="$demo['android']" color="#FFFFFF" :size="20"/>
                            </stack>
                            <text class="text-theme-on-surface font-semibold mt-1">{{ $demo['title'] }}</text>
                            <text class="text-theme-on-surface-variant text-sm">{{ $demo['subtitle'] }}</text>
                        </column>
                    </pressable>
                @endforeach
            </row>
        </scroll-view>

        {{-- Motion / gesture demo --}}
        <text class="text-theme-on-surface-variant text-sm font-semibold">MOTION</text>
        <stack @doubleTap="doubleTapped"
               class="w-full bg-indigo-600 dark:bg-indigo-800 rounded-3xl shadow-xl p-6 items-center justify-center">
            <text class="text-white text-center  font-semibold">{{ $gesture }}</text>
        </stack>

        {{-- About --}}
        <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
            <text class="text-theme-on-surface font-semibold">Built with Element</text>
            <text class="text-theme-on-surface-variant text-sm">Each screen is a PHP class and a Blade template, rendered to SwiftUI and Jetpack Compose by NativePHP's Element engine.</text>
        </column>
    </column>
</scroll-view>
