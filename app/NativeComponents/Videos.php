<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Concerns\SearchesDocs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * Videos tab — the NativePHP YouTube channel feed (RSS), fetched + parsed
 * server-side and cached 6h. Featured card + list, matching the Jump app.
 * #[Lazy] paints the skeleton while a cold cache is fetched; a warm cache
 * paints the real feed straight away (see publishPlaceholder()).
 */
#[Lazy]
class Videos extends NativeComponent
{
    use InteractsWithDiscovery;
    use SearchesDocs;

    private const FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=UCbkAE6vLlR6lOy_nxd--22g';

    private const CACHE_KEY = 'jump.videos';

    /** @var array<int,array<string,string>> */
    public array $videos = [];

    public bool $failed = false;

    protected function placeholder(): Element|View
    {
        return view('native.videos-skeleton');
    }

    /**
     * Skip the skeleton when the feed is already cached — load() returns from
     * cache within the frame, so a skeleton would just be a one-frame flash.
     * Only a cold cache pays the fetch that the skeleton exists to cover.
     */
    public function publishPlaceholder(): void
    {
        if (Cache::has(self::CACHE_KEY)) {
            return;
        }

        parent::publishPlaceholder();
    }

    public function navTitle(): string
    {
        return 'Videos';
    }

    public function mount(): void
    {
        $this->startDiscovery();
        $this->load();
    }

    public function reload(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->failed = false;
        $this->load();
    }

    private function load(): void
    {
        try {
            $this->videos = Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => $this->fetch());
            $this->failed = empty($this->videos);
        } catch (\Throwable) {
            $this->failed = true;
        }
    }

    /** @return array<int,array<string,string>> */
    private function fetch(): array
    {
        $body = Http::timeout(10)->get(self::FEED)->body();
        $xml = @simplexml_load_string($body);
        if (! $xml) {
            return [];
        }
        $yt = 'http://www.youtube.com/xml/schemas/2015';
        $media = 'http://search.yahoo.com/mrss/';

        $out = [];
        foreach ($xml->entry ?? [] as $entry) {
            $id = (string) $entry->children($yt)->videoId;
            if ($id === '') {
                continue;
            }
            $title = (string) $entry->title;
            $desc = (string) $entry->children($media)->group->children($media)->description;
            $published = (string) $entry->published;

            $out[] = [
                'id' => $id,
                'title' => $title,
                'description' => $desc,
                'date' => $this->relativeDate($published),
                'category' => $this->category($title),
                'thumb' => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
                'url' => "https://www.youtube.com/watch?v={$id}",
            ];
        }

        return $out;
    }

    private function category(string $title): string
    {
        $t = strtolower($title);

        return match (true) {
            str_contains($t, 'live') => 'LIVE',
            str_contains($t, 'demo') => 'DEMO',
            str_contains($t, 'explained') || str_contains($t, 'deep dive') => 'DEEP DIVE',
            default => 'VIDEO',
        };
    }

    private function relativeDate(string $iso): string
    {
        try {
            return Carbon::parse($iso)->diffForHumans(short: true);
        } catch (\Throwable) {
            return '';
        }
    }

    public function watch(string $url): void
    {
        Browser::open($url);
    }

    public function render(): View
    {
        return view('native.videos', [
            'featured' => $this->videos[0] ?? null,
            'rest' => array_slice($this->videos, 1),
            'count' => count($this->videos),
        ]);
    }
}
