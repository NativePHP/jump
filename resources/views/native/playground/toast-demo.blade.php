<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
        <text class="text-theme-on-surface font-semibold">Toasts</text>
        <text class="text-theme-on-surface-variant text-sm">Dialog::toast() shows a transient message — snackbar on Android, overlay on iOS.</text>
    </column>

    <column class="w-full p-4 gap-4 bg-theme-surface rounded-2xl border border-theme-outline">
        <outlined-text-input label="Message" native:model.blur="message" ref="message-input" class="w-full"/>
        <select label="Duration" native:model="duration" :options="['short', 'long']" class="w-full"/>
        <button @press="showToast" icon="text.bubble.fill" ref="show-toast" class="w-full">Show toast</button>
    </column>
</column>
