<?php

namespace App\NativeComponents\Concerns;

use App\Support\DocsIndex;

/**
 * Backs the tab bar's search tab. Search results come from whichever screen
 * is ACTIVE when the user searches, so every tab screen `use`s this trait to
 * offer the same docs search app-wide. Result rows navigate through the docs
 * deep-link route ("/docs/mobile/4/{section}/{page}").
 */
trait SearchesDocs
{
    /** @return list<array{title:string,subtitle:string,url:string}> */
    public function onSearchQuery(string $query): array
    {
        return DocsIndex::search($query);
    }
}
