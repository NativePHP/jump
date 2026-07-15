<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
        <text class="text-theme-on-surface font-semibold">Biometric authentication</text>
        <text class="text-theme-on-surface-variant text-sm">Biometrics::prompt() triggers Face ID / Touch ID on iOS and the fingerprint prompt on Android. The result arrives via completed().</text>
    </column>

    {{-- Vault state --}}
    <column class="w-full p-8 gap-4 items-center bg-theme-surface rounded-3xl border border-theme-outline">
        @if($state === 'unlocked')
            <stack class="w-20 h-20 rounded-full bg-emerald-500 items-center justify-center">
                <icon ios="lock.open.fill" android="lock_open" color="#FFFFFF" :size="34"/>
            </stack>
            <text class="text-theme-on-surface text-xl font-bold">Unlocked</text>
            <badge label="Authenticated" variant="primary"/>
            <button @press="reset" variant="ghost" size="sm">Lock again</button>
        @elseif($state === 'failed')
            <stack class="w-20 h-20 rounded-full bg-red-500 items-center justify-center">
                <icon ios="xmark" android="close" color="#FFFFFF" :size="34"/>
            </stack>
            <text class="text-theme-on-surface text-xl font-bold">Authentication failed</text>
            <badge label="Try again" variant="destructive"/>
        @elseif($state === 'prompting')
            <activity-indicator/>
            <text class="text-theme-on-surface-variant">Waiting for biometrics…</text>
        @else
            <stack class="w-20 h-20 rounded-full bg-theme-surface-variant items-center justify-center">
                <icon ios="lock.fill" android="lock" color="#7C3AED" :size="34"/>
            </stack>
            <text class="text-theme-on-surface text-xl font-bold">Locked</text>
            <text class="text-theme-on-surface-variant text-sm text-center">Authenticate to unlock the demo vault.</text>
        @endif
    </column>

    @if($state !== 'unlocked')
        <button @press="authenticate" icon="faceid" class="w-full">Authenticate</button>
    @endif
</column>
