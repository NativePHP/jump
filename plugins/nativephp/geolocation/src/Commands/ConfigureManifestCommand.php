<?php

namespace NativePHP\Geolocation\Commands;

use DOMDocument;
use DOMElement;
use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * post_compile hook: inserts (or removes) the geolocation background /
 * foreground-service permissions and native components based on the two opt-in
 * config flags.
 *
 * The static nativephp.json ships only the foreground-safe baseline
 * (INTERNET + FINE/COARSE location) so apps that merely read a position never
 * trip Google Play's foreground-service / background-location declarations.
 * The heavier permissions are layered on here, only when the developer opts in:
 *
 *   NATIVEPHP_GEOLOCATION_FOREGROUND_SERVICE  → LocationWatchService (+ FGS perms),
 *                                               iOS `location` background mode
 *   NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION → the above PLUS killed-app / reboot
 *                                               survival (ACCESS_BACKGROUND_LOCATION,
 *                                               boot receiver, iOS Always string)
 *
 * Everything we touch is wrapped in `nativephp-geolocation:*` markers so the
 * pass is idempotent across incremental rebuilds and never disturbs entries
 * contributed by the app or other plugins.
 */
class ConfigureManifestCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:geolocation:configure-manifest';

    protected $description = 'Layer in the geolocation background/foreground-service permissions when opted in';

    // Fully-qualified names (core resolves ".Name" against this Kotlin package).
    protected const SERVICE = 'com.nativephp.geolocation.LocationWatchService';

    protected const RECEIVER = 'com.nativephp.geolocation.LocationWatchBootReceiver';

    protected const IOS_ALWAYS_KEY = 'NSLocationAlwaysAndWhenInUseUsageDescription';

    protected const IOS_ALWAYS_VALUE = 'This app uses your location in the background to keep recording location updates when it is not on screen';

    public function handle(): int
    {
        // Background survival re-arms the service, so it implies the foreground service.
        $background = (bool) config('nativephp-geolocation.background_location', false);
        $foreground = (bool) config('nativephp-geolocation.foreground_service', false) || $background;

        if ($this->isAndroid()) {
            $this->configureAndroid($foreground, $background);
        }

        if ($this->isIos()) {
            $this->configureIos($foreground, $background);
        }

        return self::SUCCESS;
    }

    // =========================================================================
    // Android
    // =========================================================================

    protected function configureAndroid(bool $foreground, bool $background): void
    {
        $path = $this->buildPath().'/app/src/main/AndroidManifest.xml';

        if (! is_file($path)) {
            $this->warn("AndroidManifest.xml not found at {$path} — skipping geolocation manifest configuration.");

            return;
        }

        $xml = file_get_contents($path);

        // Permissions (before <application>).
        $permissions = [];
        if ($foreground) {
            $permissions[] = 'android.permission.FOREGROUND_SERVICE';
            $permissions[] = 'android.permission.FOREGROUND_SERVICE_LOCATION';
            $permissions[] = 'android.permission.POST_NOTIFICATIONS';
        }
        if ($background) {
            $permissions[] = 'android.permission.ACCESS_BACKGROUND_LOCATION';
            $permissions[] = 'android.permission.RECEIVE_BOOT_COMPLETED';
        }
        $xml = $this->replaceMarkedBlock(
            $xml,
            'permissions',
            $this->buildPermissionsBlock($permissions),
            '<application',
            before: true,
        );

        // Components (before </application>).
        $components = '';
        if ($foreground) {
            $components .= $this->serviceEntry();
        }
        if ($background) {
            $components .= $this->receiverEntry();
        }
        $xml = $this->replaceMarkedBlock(
            $xml,
            'components',
            $components === '' ? '' : rtrim($components, "\n")."\n",
            '</application>',
            before: true,
        );

        file_put_contents($path, $xml);

        $this->reportAndroid($foreground, $background);
    }

    protected function buildPermissionsBlock(array $permissions): string
    {
        if ($permissions === []) {
            return '';
        }

        $lines = array_map(
            fn ($p) => "    <uses-permission android:name=\"{$p}\" />",
            $permissions,
        );

        return implode("\n", $lines)."\n";
    }

    protected function serviceEntry(): string
    {
        $name = self::SERVICE;

        return <<<XML
        <service
            android:name="{$name}"
            android:exported="false"
            android:foregroundServiceType="location" />

    XML;
    }

    protected function receiverEntry(): string
    {
        $name = self::RECEIVER;

        return <<<XML
        <receiver
            android:name="{$name}"
            android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.BOOT_COMPLETED" />
            </intent-filter>
        </receiver>

    XML;
    }

    /**
     * Remove any prior `nativephp-geolocation:<name>` block, then re-insert a
     * fresh one (when non-empty) adjacent to the given anchor. Idempotent.
     */
    protected function replaceMarkedBlock(string $xml, string $name, string $body, string $anchor, bool $before): string
    {
        $start = "<!-- nativephp-geolocation:{$name}:start -->";
        $end = "<!-- nativephp-geolocation:{$name}:end -->";

        // Strip the existing block (and the whitespace line it sat on).
        $xml = preg_replace(
            '/[ \t]*'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'\n?/s',
            '',
            $xml,
        );

        if (trim($body) === '') {
            return $xml;
        }

        $block = "    {$start}\n".$body."    {$end}\n";

        if ($before) {
            // Insert immediately before the anchor's line, preserving its indent.
            return preg_replace(
                '/([ \t]*'.preg_quote($anchor, '/').')/',
                $block.'$1',
                $xml,
                1,
            );
        }

        return $xml;
    }

    protected function reportAndroid(bool $foreground, bool $background): void
    {
        match (true) {
            $background => $this->info('Geolocation: background-location permissions + boot receiver enabled (Play declaration required).'),
            $foreground => $this->info('Geolocation: foreground-location service enabled (Play FGS declaration required).'),
            default => $this->info('Geolocation: foreground-only (no background/foreground-service permissions injected).'),
        };
    }

    // =========================================================================
    // iOS
    // =========================================================================

    protected function configureIos(bool $foreground, bool $background): void
    {
        $plists = [
            $this->buildPath().'/NativePHP/Info.plist',
            $this->buildPath().'/NativePHP-simulator-Info.plist',
        ];

        foreach ($plists as $path) {
            if (is_file($path)) {
                $this->configureIosPlist($path, $foreground, $background);
            }
        }

        $this->info('Geolocation: iOS location background mode '.($foreground ? 'enabled' : 'disabled').'.');
    }

    protected function configureIosPlist(string $path, bool $foreground, bool $background): void
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        if (! @$doc->load($path)) {
            $this->warn("Could not parse {$path} — skipping.");

            return;
        }

        $dict = $this->rootDict($doc);
        if (! $dict instanceof DOMElement) {
            $this->warn("Unexpected Info.plist structure at {$path} — skipping.");

            return;
        }

        // `location` background mode (foreground service on iOS).
        $this->setBackgroundMode($doc, $dict, 'location', $foreground);

        // "Always" usage string (killed-app survival).
        $this->setStringKey($doc, $dict, self::IOS_ALWAYS_KEY, self::IOS_ALWAYS_VALUE, $background);

        file_put_contents($path, $doc->saveXML());
    }

    protected function rootDict(DOMDocument $doc): ?DOMElement
    {
        $plist = $doc->getElementsByTagName('plist')->item(0);
        if (! $plist instanceof DOMElement) {
            return null;
        }

        foreach ($plist->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'dict') {
                return $child;
            }
        }

        return null;
    }

    /**
     * Find the value element that follows a <key> in a plist <dict>.
     */
    protected function valueForKey(DOMElement $dict, string $key): ?DOMElement
    {
        $children = [];
        foreach ($dict->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            }
        }

        foreach ($children as $i => $node) {
            if ($node->tagName === 'key' && $node->textContent === $key) {
                return $children[$i + 1] ?? null;
            }
        }

        return null;
    }

    protected function setBackgroundMode(DOMDocument $doc, DOMElement $dict, string $mode, bool $enabled): void
    {
        $array = $this->valueForKey($dict, 'UIBackgroundModes');

        $hasMode = function (DOMElement $arr) use ($mode): ?DOMElement {
            foreach ($arr->childNodes as $node) {
                if ($node instanceof DOMElement && $node->tagName === 'string' && $node->textContent === $mode) {
                    return $node;
                }
            }

            return null;
        };

        if ($enabled) {
            if (! $array instanceof DOMElement) {
                $keyNode = $doc->createElement('key', 'UIBackgroundModes');
                $array = $doc->createElement('array');
                $dict->appendChild($keyNode);
                $dict->appendChild($array);
            }
            if (! $hasMode($array)) {
                $array->appendChild($doc->createElement('string', $mode));
            }

            return;
        }

        // Disabled: pull `location` out; drop the key+array if it's now empty.
        if ($array instanceof DOMElement) {
            if ($node = $hasMode($array)) {
                $array->removeChild($node);
            }
            if ($array->getElementsByTagName('string')->length === 0) {
                // Remove both the <key> and the now-empty <array>.
                $prev = $array->previousSibling;
                while ($prev && ! ($prev instanceof DOMElement)) {
                    $prev = $prev->previousSibling;
                }
                if ($prev instanceof DOMElement && $prev->tagName === 'key' && $prev->textContent === 'UIBackgroundModes') {
                    $dict->removeChild($prev);
                }
                $dict->removeChild($array);
            }
        }
    }

    protected function setStringKey(DOMDocument $doc, DOMElement $dict, string $key, string $value, bool $enabled): void
    {
        $existing = $this->valueForKey($dict, $key);

        if ($enabled) {
            if ($existing instanceof DOMElement) {
                return; // leave any app-provided value untouched
            }
            $dict->appendChild($doc->createElement('key', $key));
            $node = $doc->createElement('string');
            $node->appendChild($doc->createTextNode($value));
            $dict->appendChild($node);

            return;
        }

        // Only remove the string THIS hook injected (recognized by its own
        // default value). An app-provided string — e.g. declared via
        // nativephp.permissions to satisfy ITMS-90683, which flags the mere
        // presence of the background-location APIs in the binary — must
        // survive even with background_location disabled.
        if ($existing instanceof DOMElement && $existing->textContent === $value) {
            $prev = $existing->previousSibling;
            while ($prev && ! ($prev instanceof DOMElement)) {
                $prev = $prev->previousSibling;
            }
            if ($prev instanceof DOMElement && $prev->tagName === 'key' && $prev->textContent === $key) {
                $dict->removeChild($prev);
            }
            $dict->removeChild($existing);
        }
    }
}
