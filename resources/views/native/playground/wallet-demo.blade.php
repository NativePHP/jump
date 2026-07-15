<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
        <text class="text-theme-on-surface font-semibold">Apple Pay / Google Pay</text>
        <text class="text-theme-on-surface-variant text-sm">MobileWallet presents the native payment sheet via Stripe. This demo requires Stripe keys in the native build.</text>
    </column>

    @if(! $available)
        <column class="w-full p-8 gap-3 items-center bg-theme-surface rounded-2xl border border-theme-outline">
            <icon ios="creditcard.trianglebadge.exclamationmark" android="credit_card_off" color="#64748B" :size="34"/>
            <text class="text-theme-on-surface font-semibold">Wallet unavailable</text>
            <text class="text-theme-on-surface-variant text-sm text-center">The wallet handler isn't present on this build, or the simulator doesn't support payments.</text>
        </column>
    @else
        {{-- Fake cart --}}
        <column class="w-full p-4 gap-3 bg-theme-surface rounded-2xl border border-theme-outline">
            <row class="w-full justify-between">
                <text class="text-theme-on-surface">Rubber duck (native edition)</text>
                <text class="text-theme-on-surface font-semibold">$19.99</text>
            </row>
            <divider class="w-full"/>
            <row class="w-full justify-between">
                <text class="text-theme-on-surface font-bold">Total</text>
                <text class="text-theme-on-surface font-bold">$19.99</text>
            </row>
        </column>

        @if($state === 'completed')
            <column class="w-full p-6 gap-3 items-center bg-emerald-500 rounded-2xl">
                <icon ios="checkmark.seal.fill" android="verified" color="#FFFFFF" :size="34"/>
                <text class="text-white font-bold text-lg">Payment complete</text>
                <text class="text-white/80 text-sm text-center">{{ $detail }}</text>
                <button @press="reset" variant="ghost" size="sm">Start over</button>
            </column>
        @else
            <button @press="pay" icon="creditcard.fill" :loading="$state === 'processing'" class="w-full">
                Pay $19.99
            </button>

            @if($detail)
                <column class="w-full p-4 bg-theme-surface rounded-2xl border border-theme-outline">
                    <text class="text-theme-on-surface-variant">{{ $detail }}</text>
                </column>
            @endif
        @endif
    @endif
</column>
