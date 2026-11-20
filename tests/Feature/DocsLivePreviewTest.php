<?php

use App\NativeComponents\Docs;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Testing\Native;

/**
 * The live-preview preflight runs from render(), before the screen's native
 * Blade render turns the native tag compiler on. It must compile natively
 * anyway: a plain-HTML compile of the snippet is what the preview then
 * rendered on device (opcache kept the stale file), leaving every card empty.
 */
test('the live preview preflight compiles snippets as native views', function () {
    fakeDocsApi();
    $docs = Native::visit('/docs')->instance();
    $preflight = new ReflectionMethod(Docs::class, 'canRenderLive');
    // Unique per run, so a compiled view cached by an earlier run can't answer.
    $run = uniqid();

    expect(NativeTagPrecompiler::active())->toBeFalse()
        // Only the native compiler knows this tag doesn't exist. Compiled as
        // plain HTML it passes through as markup and the preflight says yes.
        ->and($preflight->invoke($docs, '<native:definitely-not-an-element /> {{-- '.$run.' --}}'))->toBeFalse()
        ->and($preflight->invoke($docs, '<native:text>Hello</native:text> {{-- '.$run.' --}}'))->toBeTrue()
        ->and(NativeTagPrecompiler::active())->toBeFalse();
});
