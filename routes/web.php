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
    // Everything under /docs/mobile/ is claimed as an Android app link (see
    // NATIVEPHP_DEEPLINK_PATHS), so every depth the website serves needs a route
    // here — an unrouted one opens the app and dead-ends on a local 404.
    Route::native('/docs/mobile/{version}', Docs::class)->name('docs.version');
    Route::native('/docs/mobile/{version}/{section}', Docs::class)->name('docs.section');
    Route::native('/docs/mobile/{version}/{section}/{page}', Docs::class)->name('docs.page');
    // Nested sections ("plugins/core") make the website path one segment
    // deeper. Without this the Core Plugins pages have no route at all — both
    // a link from the site and a tap on a search result dead-end on a 404.
    Route::native('/docs/mobile/{version}/{section}/{subsection}/{page}', Docs::class)->name('docs.page.nested');
    Route::native('/videos', Videos::class)->name('videos');
});

// Pushed detail screens: plain stack chrome with a native back chevron.
// The playground is a single scrolling page of NativePHP API demos — each
// card pairs the PHP snippet with a live example of that exact call.
Route::nativeGroup(AppStackLayout::class, function () {
    Route::native('/settings', Settings::class)->name('settings');
    Route::native('/playground', Playground\Home::class)->name('playground');
});
