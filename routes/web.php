<?php

use App\NativeComponents\Builds;
use App\NativeComponents\Docs;
use App\NativeComponents\Home;
use App\NativeComponents\Layouts\AppStackLayout;
use App\NativeComponents\Layouts\JumpTabsLayout;
use App\NativeComponents\Playground;
use App\NativeComponents\Settings;
use App\NativeComponents\Videos;
use Illuminate\Support\Facades\Route;

// Jump shell: native bottom-tab chrome over the four top-level screens.
Route::nativeGroup(JumpTabsLayout::class, function () {
    Route::native('/', Home::class)->name('home');
    Route::native('/builds', Builds::class)->name('builds');
    Route::native('/docs', Docs::class)->name('docs');
    Route::native('/docs/mobile/{version}/{section}/{page}', Docs::class)->name('docs.page');
    Route::native('/videos', Videos::class)->name('videos');
});

// Pushed detail screens: plain stack chrome with a native back chevron.
// The playground is a single scrolling page of NativePHP API demos — each
// card pairs the PHP snippet with a live example of that exact call.
Route::nativeGroup(AppStackLayout::class, function () {
    Route::native('/settings', Settings::class)->name('settings');
    Route::native('/playground', Playground\Home::class)->name('playground');
});
