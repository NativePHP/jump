<?php

use App\NativeComponents\Builds;
use App\NativeComponents\Docs;
use App\NativeComponents\Home;
use App\NativeComponents\Layouts\AppStackLayout;
use App\NativeComponents\Layouts\JumpTabsLayout;
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

Route::nativeGroup(AppStackLayout::class, function () {
    Route::native('/settings', Settings::class)->name('settings');
});
