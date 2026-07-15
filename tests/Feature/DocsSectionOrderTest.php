<?php

use App\Support\DocsIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The site's navigation API emits sections in sidebar order (front-matter
 * `order`, nested subsections after their parent — DocsSearchService::
 * getNavigation as of 2026-07-14). DocsIndex must preserve that key order
 * verbatim so the TOC and prev/next flattening match the website.
 */
test('sections keep the API key order', function () {
    Cache::forget('jump.docs');

    Http::fake([
        config('jump.docs_url') => Http::response([
            'navigation' => [
                'getting-started' => [['id' => 'mobile/4/getting-started/introduction', 'title' => 'Introduction', 'order' => 1]],
                'architecture' => [['id' => 'mobile/4/architecture/overview', 'title' => 'Overview', 'order' => 1]],
                'plugins' => [['id' => 'mobile/4/plugins/authoring', 'title' => 'Authoring', 'order' => 1]],
                'core' => [['id' => 'mobile/4/core/camera', 'title' => 'Camera', 'order' => 1]],
            ],
        ]),
        '*' => Http::response('', 404),
    ]);

    expect(array_column(DocsIndex::sections(), 'slug'))
        ->toBe(['getting-started', 'architecture', 'plugins', 'core']);
});
