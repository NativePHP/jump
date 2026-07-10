<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared docs corpus — the NativePHP mobile docs fetched from the public MCP
 * navigation API (full page content inline), cached 24h. Feeds both the Docs
 * tab's TOC and the tab-bar search on every tab (via
 * {@see \App\NativeComponents\Concerns\SearchesDocs}).
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

    /** @return array<int,array{slug:string,name:string,pages:array<int,array<string,mixed>>}> */
    public static function sections(): array
    {
        // Network-FIRST: always fetch the latest published docs, and only fall
        // back to the last cached copy when the source is unreachable (offline,
        // or a local nativephp.test URL a device can't resolve). A pure cache
        // (Cache::remember, 24h) left the app showing day-stale docs after a
        // site update — and on a production build (APP_DEBUG=false, which the
        // build forces) it never refetched at all. The cache is now just an
        // offline fallback + a warm corpus for search(); the fresh fetch
        // overwrites it, so this self-heals a stale cache on the next open.
        try {
            $fresh = static::fetch();
            if (! empty($fresh)) {
                Cache::put('jump.docs', $fresh, now()->addHours(24));

                return $fresh;
            }
        } catch (\Throwable) {
            // fall through to the cached copy
        }

        return Cache::get('jump.docs', []);
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

        // Filter the CACHED corpus — onSearchQuery() fires per keystroke, so a
        // live fetch here would hammer the source every keypress (and fail
        // outright when it's unreachable, e.g. a nativephp.test URL from the
        // Android emulator). Warm the cache once via sections() only if the
        // Docs tab hasn't already populated it this session.
        $sections = Cache::get('jump.docs');
        if (empty($sections)) {
            try {
                $sections = static::sections();
            } catch (\Throwable) {
                $sections = [];
            }
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

        return $sections;
    }
}
