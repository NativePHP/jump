<?php

use App\NativeComponents\Docs;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Testing\Native;

/**
 * Tapping a section card pushes that section's page list as its own screen,
 * the same way a page is pushed, so it gets the native back chevron.
 */
test('tapping a section card pushes the section index', function () {
    fakeDocsApi();

    Native::visit('/docs')
        ->call('openSection', 'concepts')
        ->assertNavigatedTo('/docs/mobile/4/concepts')
        ->assertTransition(Transition::Morph);
});

test('the section index lists its pages under the section heading', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/concepts')
        ->assertScreen(Docs::class)
        ->assertSee('Deep Links')
        ->assertSee('1 pages');

    expect($screen->get('activeSection'))->toBe('concepts')
        ->and($screen->get('currentPage'))->toBeNull();
});

test('a nested section index resolves to the nested section, not a page', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/plugins/core')
        ->assertSee('Camera');

    expect($screen->get('activeSection'))->toBe('plugins/core')
        ->and($screen->get('currentPage'))->toBeNull();
});

test('the section icon carries the same ref on the card and the index heading', function () {
    fakeDocsApi();

    $grid = json_encode(Native::visit('/docs')->tree());
    $index = json_encode(Native::visit('/docs/mobile/4/concepts')->tree());

    expect($grid)->toContain('docs-section-icon-concepts')
        ->and($index)->toContain('docs-section-icon-concepts');
});
