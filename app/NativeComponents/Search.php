<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\Support\DocsIndex;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Docs search — stands in for the TabView's native search tab, which the
 * stack-based shell doesn't have. Rows navigate via the docs deep-link route.
 */
class Search extends NativeComponent
{
    use InteractsWithDiscovery;

    public string $query = '';

    public function navTitle(): string
    {
        return 'Search';
    }

    public function mount(): void
    {
        $this->startDiscovery();
    }

    public function open(string $url): void
    {
        $this->navigate($url);
    }

    public function render(): View
    {
        return view('native.search', [
            'results' => DocsIndex::search($this->query),
        ]);
    }
}
