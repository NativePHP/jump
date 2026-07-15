<?php

use Native\Mobile\Testing\Native;

/**
 * Prev/next footer on the docs page reader — neighbors come from the corpus
 * flattened in nav order (crossing section boundaries), mirroring the
 * website's footer links. fakeDocsApi() serves two pages: Introduction
 * (getting-started) then Deep Links (concepts).
 *
 * Taps flip the page IN PLACE (no navigation): a replace() to a child URI of
 * the tab root reads as a native push (system chevron) while PHP's stack
 * stays flat — backing out of that phantom level desyncs native from PHP and
 * strands the reader on a dead cached tree. Scroll-to-top comes from keying
 * the reader's scroll container by page id instead.
 */

test('the first page shows only a Next link and it opens the next page in place', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/getting-started/introduction')
        ->assertSee('Next')
        ->assertSee('Deep Links')
        ->assertDontSee('Previous')
        ->tap('Deep Links');

    expect($screen->get('page')['id'])->toBe('mobile/4/concepts/deep-links')
        ->and($screen->get('expanded'))->toContain('concepts');
});

test('the last page shows only a Previous link and it opens back in place', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/concepts/deep-links')
        ->assertSee('Previous')
        ->assertSee('Introduction')
        ->assertDontSee('Next')
        ->tap('Introduction');

    expect($screen->get('page')['id'])->toBe('mobile/4/getting-started/introduction')
        ->and($screen->get('expanded'))->toContain('getting-started');
});

test('a deep-link route still opens the linked page at mount', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/concepts/deep-links');

    expect($screen->get('page')['id'])->toBe('mobile/4/concepts/deep-links')
        ->and($screen->get('expanded'))->toContain('concepts');
});
