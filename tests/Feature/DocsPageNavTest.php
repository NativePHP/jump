<?php

use Native\Mobile\Testing\Native;

/**
 * Prev/next footer on the docs page reader — neighbors come from the corpus
 * flattened in nav order (crossing section boundaries), mirroring the
 * website's footer links. fakeDocsApi() serves two pages: Introduction
 * (getting-started) then Deep Links (concepts).
 *
 * Taps REPLACE-navigate to the page's deep-link route (not open() in place):
 * the reader remounts scrolled to the top, and flipping pages doesn't stack
 * screens behind the back gesture.
 */

test('the first page shows only a Next link and it replaces to the next page', function () {
    fakeDocsApi();

    Native::visit('/docs/mobile/4/getting-started/introduction')
        ->assertSee('Next')
        ->assertSee('Deep Links')
        ->assertDontSee('Previous')
        ->tap('Deep Links')
        ->assertReplacedWith('/docs/mobile/4/concepts/deep-links');
});

test('the last page shows only a Previous link and it replaces back', function () {
    fakeDocsApi();

    Native::visit('/docs/mobile/4/concepts/deep-links')
        ->assertSee('Previous')
        ->assertSee('Introduction')
        ->assertDontSee('Next')
        ->tap('Introduction')
        ->assertReplacedWith('/docs/mobile/4/getting-started/introduction');
});

test('the replaced-to route opens the linked page at mount', function () {
    fakeDocsApi();

    // What the replace target resolves to: a fresh Docs screen with the page
    // open (and its section expanded) — i.e. scrolled to the top by virtue of
    // being a brand-new screen.
    $screen = Native::visit('/docs/mobile/4/concepts/deep-links');

    expect($screen->get('page')['id'])->toBe('mobile/4/concepts/deep-links')
        ->and($screen->get('expanded'))->toContain('concepts');
});
