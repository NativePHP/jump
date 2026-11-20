<?php

use App\NativeComponents\Docs;
use App\Support\DocsIndex;
use App\Support\RefreshDocs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Native\Mobile\AsyncTask;
use Native\Mobile\Testing\Native;

/**
 * Docs screens read the cached corpus and never wait on the network once a
 * copy exists. A stale copy is refetched on the async lane.
 */
test('a docs screen reads the cache without fetching', function () {
    fakeDocsApi();
    DocsIndex::fresh();
    Http::fake(fn () => throw new RuntimeException('should not fetch'));

    $screen = Native::visit('/docs/mobile/4/concepts')->assertScreen(Docs::class);

    expect($screen->get('sections'))->not->toBeEmpty()
        ->and($screen->get('failed'))->toBeFalse();
});

test('the first read with no cache fetches inline', function () {
    fakeDocsApi();

    expect(DocsIndex::isStale())->toBeTrue()
        ->and(DocsIndex::sections())->not->toBeEmpty()
        ->and(DocsIndex::isStale())->toBeFalse();
    Http::assertSentCount(1);
});

test('the cache goes stale after a day', function () {
    fakeDocsApi();
    DocsIndex::fresh();

    $this->travel(23)->hours();
    expect(DocsIndex::isStale())->toBeFalse();

    $this->travel(2)->hours();
    expect(DocsIndex::isStale())->toBeTrue();
});

test('a stale cache is refreshed in the background, a fresh one is left alone', function () {
    $fake = AsyncTask::fake();
    fakeDocsApi();
    DocsIndex::fresh();

    DocsIndex::refreshInBackground();
    $fake->assertNotDispatched();

    $this->travel(25)->hours();
    DocsIndex::refreshInBackground();
    $fake->assertDispatchedTimes(1);
});

test('the background task stores a new copy', function () {
    fakeDocsApi();

    expect((new RefreshDocs)->handle())->toBeTrue()
        ->and(Cache::get('jump.docs'))->not->toBeEmpty();
});
