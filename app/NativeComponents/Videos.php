<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Concerns\SearchesDocs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * Videos tab — the NativePHP YouTube channel feed (RSS), fetched + parsed
 * server-side and cached 6h. Featured card + list, matching the Jump app.
 */
class Videos extends NativeComponent
{
    use InteractsWithDiscovery;
    use SearchesDocs;

    private const FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=UCbkAE6vLlR6lOy_nxd--22g';

    /** @var array<int,array<string,string>> */
    public array $videos = [];

    public bool $loading = true;

    public bool $failed = false;

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
        Cache::forget('jump.videos');
        $this->loading = true;
        $this->failed = false;
        $this->load();
    }

    private function load(): void
    {
        try {
            $this->videos = Cache::remember('jump.videos', now()->addHours(6), fn () => $this->fetch());
            $this->failed = empty($this->videos);
        } catch (\Throwable) {
            $this->failed = true;
        }
        $this->loading = false;
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
