{{--
    Root is a single full-height column: the scroll-view flexes to fill it and
    the How-it-works <bottom-sheet> sits alongside as an overlay (it takes no
    layout space when hidden). Making the scroll-view a sibling of the sheet
    without this wrapper collapses its height and cuts off the bottom.
--}}
{{-- The root carries bg-theme-background, not just the scroll-view: the tree
     reaches the physical screen edge, but the scroll-view's content is inset
     by the top safe area, so an unpainted root leaves the status-bar strip
     showing the host's systemBackground (white) against our off-white bg. --}}
<column class="w-full h-full bg-theme-background">
    <scroll-view class="w-full flex-1 bg-theme-background">
        <column class="w-full px-5 pb-32 safe-area">

            {{-- Discovered-servers pill is now app-wide (JumpTabsLayout::floatingOverlay). --}}

            {{-- Hero --}}
            <column class="w-full items-center pt-6 select-text">
                {{-- Kicker pill --}}
                <row
                    class="items-center gap-2 px-4 py-2 bg-theme-surface-variant rounded-full border border-theme-outline">
                    <native:icon :ios="App\Icons\Ios::BoltFill" :android="App\Icons\Android::ElectricBolt" :size="12"
                                 class="text-red-500"/>
                    <text class="text-xs font-bold text-theme-primary tracking-widest">POWERED BY NATIVEPHP</text>
                </row>

                {{-- JUMP wordmark — static mid-leap pose (padding), with a slow
                     per-letter float (translate-y yoyo). animate-delay staggers
                     each letter's phase so a soft wave travels through the word.
                     Speed lines stay still. --}}
                <row class="items-end pt-[48]">
                    <column class="items-end gap-2 pb-[24] pr-3">
                        <column class="w-[34] h-[5] rounded-full bg-theme-primary opacity-[0.6]"/>
                        <column class="w-[22] h-[5] rounded-full bg-theme-primary opacity-[0.35]"/>
                        <column class="w-[12] h-[5] rounded-full bg-theme-primary opacity-[0.18]"/>
                    </column>
                    <text font="accent" class="text-[72] leading-none italic text-theme-primary uppercase">jump</text>
                </row>

                {{-- ground line the letters leap from --}}
                <column class="w-[230] h-[4] rounded-full bg-theme-outline opacity-[0.5] mt-2"/>

                {{-- Tagline --}}
                <row class="items-end gap-2 pt-4">
                    <text font="accent"
                          class="text-xl leading-none italic font-bold text-slate-800 dark:text-slate-200">Instant
                    </text>
                    <text font="accent" class="text-xl leading-none text-red-600">Laravel</text>
                    <text font="accent"
                          class="text-xl leading-none italic font-bold text-slate-800 dark:text-slate-200">Runtime.
                    </text>
                </row>

                <text class="text-base text-theme-on-surface-variant pt-3 leading-relaxed text-center">
                    Jump turns your phone into a live Laravel machine. Scan a Jump QR code and your
                    project runs right here — natively.
                </text>

                <row class="gap-3 pt-6">
                    <pressable @press="scan">
                        <row class="items-center gap-2 px-6 py-4 bg-theme-primary rounded-2xl">
                            <native:icon :ios="App\Icons\Ios::QrcodeViewfinder"
                                         :android="App\Icons\Android::QrCodeScanner" :size="18" color="#FFFFFF"/>
                            <text font="accent" class="text-base font-bold text-theme-on-primary">JUMP</text>
                        </row>
                    </pressable>
                    <pressable @press="openHow">
                        <text
                            font="accent"
                            class="px-6 py-4 bg-theme-surface-variant rounded-2xl border border-theme-outline text-base font-semibold text-theme-on-surface">
                            How it works
                        </text>
                    </pressable>
                </row>
            </column>

            {{-- Playground card --}}
            <pressable @press="playground" class="w-full pt-8 ">
                <row class="w-full items-start gap-3 p-5 bg-theme-surface rounded-3xl border border-theme-outline">
                    <column class="w-[44] h-[44] rounded-xl bg-theme-surface-variant items-center justify-center">
                        <native:icon :ios="App\Icons\Ios::Sparkles" :android="App\Icons\Android::AutoAwesome" :size="22"
                                     class="text-red-600"/>
                    </column>
                    <column class="flex-1 gap-1">
                        <text font="accent" class="text-lg font-bold text-theme-on-surface">NativePHP Playground</text>
                        <text class="text-sm text-theme-on-surface-variant">A bundled Laravel app that demonstrates 20
                            native APIs — camera, share, scanner, secure storage, and more.
                        </text>
                    </column>
                    <native:icon :ios="App\Icons\Ios::ArrowRight" :android="App\Icons\Android::ArrowForward" :size="14"
                                 class="text-red-500"/>
                </row>
            </pressable>

            {{-- Quick access --}}
            <row class="w-full gap-3 pt-6">
                <pressable @press="goDocs" class="flex-1">
                    <column
                        class="w-full h-[190] p-5 bg-theme-surface rounded-3xl border border-theme-outline justify-between">
                        <column class="gap-2">
                            <text font="accent" class="text-lg font-bold text-theme-on-surface">Official Docs</text>
                            <text class="text-sm text-theme-on-surface-variant">Learn the Jump runtime and NativePHP
                                APIs.
                            </text>
                        </column>
                        <row class="items-center px-4 py-2 bg-theme-surface-variant rounded-xl self-start">
                            <text class="text-sm font-bold text-theme-on-surface">Browse</text>
                            <spacer/>
                            <native:icon :size="20" :android="\App\Icons\Android::Book" :ios="\App\Icons\Ios::Book"
                                         class="text-theme-primary"/>
                        </row>
                    </column>
                </pressable>
                <pressable @press="goVideos" class="flex-1">
                    <column
                        class="w-full h-[190] p-5 bg-theme-surface rounded-3xl border border-theme-outline justify-between">
                        <column class="gap-2">
                            <text font="accent" class="text-lg font-bold text-theme-on-surface">Video Guides</text>
                            <text class="text-sm text-theme-on-surface-variant">Tutorials for building with Laravel on
                                mobile.
                            </text>
                        </column>
                        <row class="items-center px-4 py-2 bg-theme-primary rounded-xl self-start">
                            <text class="text-sm font-bold text-theme-on-primary">Watch</text>
                            <spacer/>
                            <native:icon :size="20" :android="\App\Icons\Android::PlayCircleFill"
                                         :ios="\App\Icons\Ios::PlayCircleFill" class="text-white"/>
                        </row>
                    </column>
                </pressable>
            </row>

            {{-- ─────────── Agency Partners ─────────── --}}
            <column class="w-full pt-8 gap-1">
                <row class="items-center gap-2">
                    <native:icon :ios="App\Icons\Ios::Building2Fill" :android="App\Icons\Android::Groups" :size="16"
                                 class="text-red-600"/>
                    <text font="accent" class="text-xs font-bold text-theme-primary tracking-widest">AGENCY PARTNERS
                    </text>
                </row>
                <text class="text-base text-theme-on-surface-variant pt-1">Vetted agencies with deep NativePHP
                    experience who can help bring your project to life.
                </text>
            </column>

            {{-- Bare tappable logos (dark-variant rasters swap in via the
                 component) — sized per-logo to keep aspect ratio, so no card
                 chrome or text to fight platform layout quirks. --}}
            <column class="w-full pt-6 pb-2 gap-8 items-center">
                @foreach ($partners as $partner)
                    <pressable @press="openPartner('{{ $partner['url'] }}')">
                        <image src="{{ $partner['logo'] }}" alt="{{ $partner['name'] }} logo" fit="1"
                               class="w-[{{ $partner['width'] }}] h-[{{ $partner['height'] }}]"/>
                    </pressable>
                @endforeach
            </column>

        </column>
    </scroll-view>

    {{-- ─────────── How Jump Works — dismissable modal ─────────── --}}
    <bottom-sheet :visible="$showHow" @dismiss="closeHow" detents="large">
        <scroll-view class="w-full h-full bg-theme-background">
            <column class="w-full px-6 pb-16 pt-4 gap-6">
                <row class="w-full items-center justify-between">
                    <text class="text-3xl font-black text-theme-on-surface">How Jump Works</text>
                    <pressable @press="closeHow">
                        <native:icon :ios="App\Icons\Ios::XmarkCircleFill" :android="App\Icons\Android::Cancel"
                                     :size="28"
                                     color="#475569" dark-color="#94A3B8"/>
                    </pressable>
                </row>
                <text class="text-base text-theme-on-surface-variant">A mobile programming environment for Laravel and
                    PHP.
                </text>

                @foreach ([
                    ['n' => '1', 'ios' => App\Icons\Ios::Terminal, 'android' => App\Icons\Android::Terminal, 'title' => 'Start the Jump CLI', 'body' => 'In a Laravel application, run composer require nativephp/mobile, then php artisan native:jump to prepare it for the mobile runtime.'],
                    ['n' => '2', 'ios' => App\Icons\Ios::QrcodeViewfinder, 'android' => App\Icons\Android::QrCodeScanner, 'title' => 'Scan the QR Code', 'body' => 'A QR code will appear in your terminal. Tap "Scan" in this app and point your camera at it.'],
                    ['n' => '3', 'ios' => App\Icons\Ios::BoltHorizontalFill, 'android' => App\Icons\Android::Bolt, 'title' => 'Instant Connection', 'body' => 'Your phone connects over your local network and runs the project in the Jump runtime.'],
                    ['n' => '4', 'ios' => App\Icons\Ios::HandTapFill, 'android' => App\Icons\Android::TouchApp, 'title' => 'Explore Native APIs', 'body' => "Access camera, push notifications, biometrics, geolocation, and more through NativePHP's Laravel APIs — they execute right on your phone."],
                    ['n' => '5', 'ios' => App\Icons\Ios::ArrowTriangle2Circlepath, 'android' => App\Icons\Android::Sync, 'title' => 'Live Reload', 'body' => 'Save a file and the runtime reloads automatically. Shake to exit and return here when you\'re done.'],
                ] as $step)
                    <row class="w-full items-start gap-4">
                        <column class="w-[44] h-[44] rounded-full bg-theme-primary items-center justify-center">
                            <native:icon :ios="$step['ios']" :android="$step['android']" :size="20" color="#FFFFFF"/>
                        </column>
                        <column class="flex-1 gap-1">
                            <text class="text-xs font-bold text-theme-primary tracking-widest">
                                STEP {{ $step['n'] }}</text>
                            <text class="text-lg font-bold text-theme-on-surface">{{ $step['title'] }}</text>
                            <text class="text-sm text-theme-on-surface-variant">{{ $step['body'] }}</text>
                        </column>
                    </row>
                @endforeach
            </column>
        </scroll-view>
    </bottom-sheet>
</column>
