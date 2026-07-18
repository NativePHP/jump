<?php

use App\NativeComponents\Docs;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

/**
 * Pull-to-refresh on the Docs tab (both the TOC and the page reader):
 * Docs::refresh() refetches the corpus and re-resolves the open page, so
 * published edits show up without leaving the screen.
 */

/** Fake the docs API against a page list the test can mutate mid-flight. */
function fakeMutableDocsApi(array &$pages): void
{
    Http::fake(function ($request) use (&$pages) {
        if (str_starts_with($request->url(), config('jump.docs_url'))) {
            return Http::response(['navigation' => ['getting-started' => $pages]]);
        }

        return Http::response('', 404);
    });
}

test('refresh refetches the corpus and updates the open page in place', function () {
    $pages = [
        ['id' => 'mobile/4/getting-started/introduction', 'title' => 'Introduction', 'description' => '', 'content' => 'Old body.', 'order' => 1],
    ];
    fakeMutableDocsApi($pages);

    $screen = Native::visit('/docs/mobile/4/getting-started/introduction')
        ->assertScreen(Docs::class);

    expect($screen->get('page')['content'])->toBe('Old body.');

    $pages[0]['content'] = 'New body.';
    $screen->call('refresh');

    expect($screen->get('page'))->not->toBeNull()
        ->and($screen->get('page')['content'])->toBe('New body.')
        ->and($screen->get('failed'))->toBeFalse();
});

test('refresh falls back to the TOC when the open page vanished upstream', function () {
    $pages = [
        ['id' => 'mobile/4/getting-started/introduction', 'title' => 'Introduction', 'description' => '', 'content' => 'Intro.', 'order' => 1],
        ['id' => 'mobile/4/getting-started/old-page', 'title' => 'Old Page', 'description' => '', 'content' => 'Soon gone.', 'order' => 2],
    ];
    fakeMutableDocsApi($pages);

    $screen = Native::visit('/docs/mobile/4/getting-started/old-page');
    expect($screen->get('page'))->not->toBeNull();

    array_splice($pages, 1, 1); // the page is removed from the published docs
    $screen->call('refresh');

    expect($screen->get('page'))->toBeNull()
        ->and($screen->get('sections'))->not->toBeEmpty();
});

test('refresh keeps the current view when the source is unreachable', function () {
    fakeDocsApi();

    $screen = Native::visit('/docs/mobile/4/concepts/deep-links');
    expect($screen->get('page'))->not->toBeNull();

    // Simulate going offline with an empty cache: the fetch throws and
    // sections() has nothing to fall back to — refresh must be a no-op,
    // not blank the screen.
    Cache::forget('jump.docs');
    Http::fake([
        config('jump.docs_url') => fn () => throw new ConnectionException('unreachable'),
        '*' => Http::response('', 404),
    ]);

    $screen->call('refresh');

    expect($screen->get('page'))->not->toBeNull()
        ->and($screen->get('page')['id'])->toBe('mobile/4/concepts/deep-links')
        ->and($screen->get('failed'))->toBeFalse();
});
