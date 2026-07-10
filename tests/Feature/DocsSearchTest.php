<?php

use Native\Mobile\Testing\Native;

/**
 * The tab bar's search tab: every tab screen answers onSearchQuery() from the
 * shared docs corpus (SearchesDocs → DocsIndex), and result rows navigate via
 * the docs deep-link route.
 */

test('searching from the docs tab returns matching pages with deep-link urls', function () {
    fakeDocsApi();

    $results = Native::visit('/docs')
        ->search('deep')
        ->searchResults();

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Deep Links')
        ->and($results[0]['subtitle'])->toBe('Concepts')
        ->and($results[0]['url'])->toBe('/docs/mobile/4/concepts/deep-links');
});

test('title matches rank above body matches', function () {
    fakeDocsApi();

    // "welcome" appears in Introduction's description/content only; "links"
    // is in the Deep Links title — a title hit must sort first.
    $results = Native::visit('/docs')->search('links')->searchResults();

    expect($results[0]['title'])->toBe('Deep Links');
});

test('every tab answers the docs search', function () {
    fakeDocsApi();

    foreach (['/', '/builds', '/videos'] as $uri) {
        $results = Native::visit($uri)->search('introduction')->searchResults();

        expect($results)->not->toBeEmpty()
            ->and($results[0]['url'])->toBe('/docs/mobile/4/getting-started/introduction');
    }
});

test('a blank query returns nothing', function () {
    fakeDocsApi();

    expect(Native::visit('/docs')->search('  ')->searchResults())->toBeEmpty();
});

test('search filters the cached corpus and survives an unreachable source', function () {
    // Warm the cache from a reachable source (as opening the Docs tab would).
    fakeDocsApi();
    App\Support\DocsIndex::sections();

    // Now the docs source is unreachable — the exact Android-emulator case
    // where a nativephp.test URL can't resolve. Per-keystroke search must NOT
    // re-fetch and fail; it filters the last good corpus from the cache.
    Illuminate\Support\Facades\Http::fake([
        config('jump.docs_url') => fn () => throw new Illuminate\Http\Client\ConnectionException('unreachable'),
    ]);

    $results = Native::visit('/')->search('deep')->searchResults();

    expect($results)->toHaveCount(1)
        ->and($results[0]['url'])->toBe('/docs/mobile/4/concepts/deep-links');
});
