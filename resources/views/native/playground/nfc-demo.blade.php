<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-8 gap-4 items-center bg-theme-surface rounded-3xl border border-theme-outline mt-4">
        <stack class="w-20 h-20 rounded-full bg-theme-surface-variant items-center justify-center">
            <icon ios="wave.3.right" android="nfc" color="#64748B" :size="34"/>
        </stack>
        <text class="text-theme-on-surface text-xl font-bold">NFC coming soon</text>
        <text class="text-theme-on-surface-variant text-sm text-center">Tag reading and writing isn't available on the Element runtime yet. It will return here as soon as the NFC plugin ships.</text>
        <badge label="Not available on this build" variant="accent"/>
    </column>
</column>
