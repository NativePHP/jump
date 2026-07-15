<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * NFC has no PHP API on the mobile-air `element` branch (the old
 * Native\Mobile\Facades\Nfc from the released package was removed and no
 * nfc plugin exists yet). This screen ships as a placeholder so the demo
 * catalog stays complete; wire it up when an nfc plugin lands.
 *
 * The builddemo spec it should reproduce: Nfc::isAvailable(), Nfc::read()
 * with TagRead/ScanCancelled/NfcError events, and Nfc::write($type,
 * $content) with a TagWritten event and a text/URI write form.
 */
class NfcDemo extends NativeComponent
{
    public function navTitle(): string
    {
        return 'NFC';
    }

    public function render(): View
    {
        return view('native.playground.nfc-demo');
    }
}
