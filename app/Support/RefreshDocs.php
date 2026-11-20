<?php

namespace App\Support;

use Native\Mobile\AsyncTask;

/**
 * Refetch the docs corpus on the async lane. The result goes straight into
 * the cache, which the async and UI lanes share, so nothing needs to come
 * back to the screen that dispatched it.
 */
class RefreshDocs extends AsyncTask
{
    public function handle(): bool
    {
        return DocsIndex::fresh() !== [];
    }
}
