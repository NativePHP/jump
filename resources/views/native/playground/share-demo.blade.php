<column class="w-full h-full bg-theme-background px-5 pt-2 gap-5">

    <column class="w-full p-4 gap-1 bg-theme-surface-variant rounded-2xl">
        <text class="text-theme-on-surface font-semibold">System share sheet</text>
        <text class="text-theme-on-surface-variant text-sm">Dialog::share() opens the native share sheet with a title, text and URL.</text>
    </column>

    <column class="w-full p-4 gap-4 bg-theme-surface rounded-2xl border border-theme-outline">
        <outlined-text-input label="Title" native:model.blur="title" class="w-full"/>
        <outlined-text-input label="Text" native:model.blur="text" class="w-full"/>
        <outlined-text-input label="URL" keyboard="url" native:model.blur="url" class="w-full"/>
        <button @press="share" icon="square.and.arrow.up" class="w-full">Share</button>
    </column>
</column>
