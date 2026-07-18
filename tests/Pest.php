<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Fake the docs navigation API so screens never hit the network (debug
 * builds bypass the docs cache, so faking Http is the reliable seam).
 * Page ids mirror the website path after /docs/ — exactly what the live
 * API returns.
 */
function fakeDocsApi(): void
{
    Http::fake([
        config('jump.docs_url') => Http::response([
            'navigation' => [
                'getting-started' => [
                    ['id' => 'mobile/4/getting-started/introduction', 'title' => 'Introduction', 'description' => 'Welcome to NativePHP.', 'content' => 'Welcome.', 'order' => 1],
                ],
                'concepts' => [
                    ['id' => 'mobile/4/concepts/deep-links', 'title' => 'Deep Links', 'description' => 'Universal and app links.', 'content' => '## Deep Links', 'order' => 1],
                ],
            ],
        ]),
        // Block everything else (e.g. the Videos tab's RSS fetch) — screens
        // degrade gracefully and tests must not depend on the live network.
        '*' => Http::response('', 404),
    ]);
}
