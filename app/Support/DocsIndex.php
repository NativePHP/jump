<?php

namespace App\Support;

use App\NativeComponents\Concerns\SearchesDocs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared docs corpus — the NativePHP mobile docs fetched from the public MCP
 * navigation API (full page content inline). Read from the cache, refetched
 * in the background once a day. Feeds both the Docs
 * tab's TOC and the tab-bar search on every tab (via
 * {@see SearchesDocs}).
 */
class DocsIndex
{
    private const SECTION_NAMES = [
        'getting-started' => 'Getting Started',
        'the-basics' => 'The Basics',
        'concepts' => 'Concepts',
        'architecture' => 'Architecture',
        'super-native' => 'Super Native',
        'edge-components' => 'Edge Components',
        'testing' => 'Testing',
        'plugins' => 'Plugins',
        'core' => 'Core Plugins',
    ];

    /** Cache key for the corpus. Kept forever, so the app works offline. */
    private const KEY = 'jump.docs';

    /** When the corpus was last fetched, for deciding when to refetch. */
    private const FETCHED_AT = 'jump.docs.fetched_at';

    /** How old the cached corpus can get before a background refetch. */
    private const MAX_AGE_HOURS = 24;

    /**
     * Cache-first. Every Docs screen calls this in mount(), and each pushed
     * section or page is a new screen, so a network fetch here put a full
     * download of the corpus in front of every push. The network is only
     * hit inline when there is no cached copy at all (first launch). Keeping
     * it fresh is {@see refreshInBackground()}'s job, and pull-to-refresh
     * uses {@see fresh()}.
     *
     * @return array<int,array{slug:string,name:string,pages:array<int,array<string,mixed>>}>
     */
    public static function sections(): array
    {
        $cached = Cache::get(self::KEY);
        if (! empty($cached)) {
            return $cached;
        }

        return static::fresh();
    }

    /**
     * Fetch the latest published docs now and cache them. Falls back to the
     * cached copy when the source is unreachable (offline, or a local
     * nativephp.test URL a device can't resolve).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fresh(): array
    {
        try {
            $fresh = static::fetch();
            if (! empty($fresh)) {
                Cache::forever(self::KEY, $fresh);
                Cache::forever(self::FETCHED_AT, now()->getTimestamp());

                return $fresh;
            }
        } catch (\Throwable) {
            // fall through to the cached copy
        }

        return Cache::get(self::KEY, []);
    }

    /** True when there's no cached copy, or it's older than a day. */
    public static function isStale(): bool
    {
        $fetchedAt = Cache::get(self::FETCHED_AT);

        return ! is_int($fetchedAt)
            || empty(Cache::get(self::KEY))
            || now()->getTimestamp() - $fetchedAt > self::MAX_AGE_HOURS * 3600;
    }

    /**
     * Refetch the docs on the async lane if the cached copy is stale, so the
     * UI never waits on it. Called when the app boots (Home) and when the
     * Docs tab opens. The next Docs screen picks up the new copy.
     */
    public static function refreshInBackground(): void
    {
        if (! static::isStale()) {
            return;
        }

        try {
            RefreshDocs::dispatch()->timeout(60);
        } catch (\Throwable) {
            // No async lane (tests, an old shell). The next stale check retries.
        }
    }

    /**
     * Search page titles / descriptions / body text; title hits rank first.
     * Rows are SearchItem object-shape arrays whose `url` navigates through
     * the docs deep-link route (page ids mirror the website path after
     * /docs/, e.g. "mobile/4/concepts/deep-links").
     *
     * @return list<array{title:string,subtitle:string,url:string}>
     */
    public static function search(string $query, int $limit = 15): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // onSearchQuery() fires per keystroke. sections() reads the cache and
        // only fetches when there is no copy yet.
        try {
            $sections = static::sections();
        } catch (\Throwable) {
            $sections = [];
        }
        if (empty($sections)) {
            return [];
        }

        $hits = [];
        foreach ($sections as $section) {
            foreach ($section['pages'] as $page) {
                $rank = match (true) {
                    stripos($page['title'], $query) !== false => 0,
                    stripos($page['description'], $query) !== false => 1,
                    stripos($page['content'], $query) !== false => 2,
                    default => null,
                };
                if ($rank === null) {
                    continue;
                }
                $hits[] = [$rank, [
                    'title' => $page['title'],
                    'subtitle' => $section['name'],
                    'url' => '/docs/'.$page['id'],
                ]];
            }
        }

        usort($hits, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_column(array_slice($hits, 0, $limit), 1);
    }

    /** @return array<int,array<string,mixed>> */
    private static function fetch(): array
    {
        $nav = Http::timeout(12)
            ->withoutVerifying()
            ->get(config('jump.docs_url'))
            ->json('navigation') ?? [];

        $sections = [];
        foreach ($nav as $slug => $pages) {
            if (! is_array($pages)) {
                continue;
            }
            usort($pages, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
            $sections[] = [
                'slug' => $slug,
                'name' => self::SECTION_NAMES[$slug] ?? Str::headline($slug),
                'pages' => array_map(fn ($p) => [
                    'id' => $p['id'] ?? ($slug.'/'.($p['slug'] ?? '')),
                    'title' => $p['title'] ?? 'Untitled',
                    'description' => $p['description'] ?? '',
                    'content' => $p['content'] ?? '',
                ], $pages),
            ];
        }

        // Section order is the API's key order — since 2026-07-14 the site's
        // getNavigation() emits sections in sidebar order (front-matter
        // `order`, nested subsections after their parent), so the TOC and
        // prev/next flattening match the website without a hardcoded list
        // here that would rot when sections change.
        return $sections;
    }
}
