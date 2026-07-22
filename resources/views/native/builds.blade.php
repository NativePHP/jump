{{-- THEME-TOKEN POC — this screen adapts to light/dark via config/native-ui.php.
     All other screens remain on hardcoded dark until this is verified on-device. --}}
<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full h-full items-center justify-center px-8 gap-6 py-24">
        <native:icon :ios="App\Icons\Ios::CloudFill" :android="App\Icons\Android::Cloud" :size="60" class="text-red-600"/>
        <text class="text-2xl font-bold text-theme-on-background text-center">Ship with Bifrost</text>
        <text class="text-base text-theme-on-surface-variant text-center">The fastest way to get your NativePHP app onto the App Store and Google Play. Cloud builds, code signing, and store delivery — right from your phone.</text>
        <pressable @press="openBifrost">
            <row class="items-center gap-2 px-6 py-4 bg-theme-primary rounded-xl">
                <native:icon :ios="App\Icons\Ios::PersonFill" :android="App\Icons\Android::Person" :size="16" color="#FFFFFF"/>
                <text class="text-base font-bold text-theme-on-primary">Sign In</text>
            </row>
        </pressable>
    </column>
</scroll-view>
