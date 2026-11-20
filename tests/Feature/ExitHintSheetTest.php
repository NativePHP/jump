<?php

use Native\Mobile\Testing\Native;

/**
 * The "Before you Jump" coaching sheet opens at the `medium` detent. Both
 * renderers hand the sheet's children the detent's height and clip whatever
 * overflows, and on a small phone (iPhone 13 mini, 375×812pt) this sheet's
 * content is taller than that — so its body has to scroll, or the checkbox
 * and the "Got it" button sit below the fold with no way to reach them.
 */
test('the exit-hint sheet body scrolls', function () {
    Native::visit('/')
        ->set('showExitHint', true)
        ->assertElement('bottom_sheet', fn (array $node) => ($node['props']['visible'] ?? false)
            && ($node['children'][0]['type'] ?? null) === 'scroll_view');
});
