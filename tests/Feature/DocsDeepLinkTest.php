<?php

use App\NativeComponents\Docs;
use Native\Mobile\Testing\Native;

/**
 * Universal/app links: nativephp.com's verification files route /docs/* into
 * the app and the native shells navigate to the link's path verbatim, so
 * /docs/mobile/{v}/{section}/{page} must resolve and open the linked page.
 * The docs corpus comes from fakeDocsApi() (tests/Pest.php).
 */

test('a docs universal link opens the linked page', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/concepts/deep-links')
        ->assertScreen(Docs::class);

    expect($screen->get('page'))->not->toBeNull()
        ->and($screen->get('page')['id'])->toBe('mobile/4/concepts/deep-links')
        ->and($screen->get('expanded'))->toContain('concepts');
});

test('a link to an older docs version falls back to the v4 page', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/2/concepts/deep-links');

    expect($screen->get('page'))->not->toBeNull()
        ->and($screen->get('page')['id'])->toBe('mobile/4/concepts/deep-links');
});

test('an unknown docs link falls back to the TOC', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/concepts/does-not-exist');

    expect($screen->get('page'))->toBeNull()
        ->and($screen->get('sections'))->not->toBeEmpty();
});

test('the plain docs route still opens the TOC', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs');

    expect($screen->get('page'))->toBeNull()
        ->and($screen->get('expanded'))->toBe(['getting-started']);
});
