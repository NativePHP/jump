<?php

use NativePHP\Geolocation\Events\AppEnteredForeground;

/**
 * Plugin validation tests for Geolocation.
 *
 * Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    // PLUGIN_PATH lets CI run this suite from an external Pest harness
    // (the plugin's own composer deps aren't resolvable on public
    // runners); locally the suite sits inside the plugin as usual.
    $this->pluginPath = getenv('PLUGIN_PATH') ?: dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();

        json_decode(file_get_contents($this->manifestPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest)->toHaveKeys(['namespace', 'bridge_functions']);
        expect($manifest['namespace'])->toBe('Geolocation');
    });

    it('declares every bridge function for both platforms', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['bridge_functions'])->toBeArray()->not->toBeEmpty();

        $names = array_column($manifest['bridge_functions'], 'name');
        expect($names)->toContain(
            'Geolocation.GetCurrentPosition',
            'Geolocation.WatchPosition',
            'Geolocation.ClearWatch',
            'Geolocation.CheckPermissions',
            'Geolocation.RequestPermissions',
            'Geolocation.StartBackgroundWatch',
            'Geolocation.StopBackgroundWatch',
            'Geolocation.DrainWatchBuffer',
            'Geolocation.TrimWatchBuffer',
            'Geolocation.BackgroundWatchStatus',
        );

        foreach ($manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name']);
            expect(isset($function['android']) || isset($function['ios']))->toBeTrue();
        }
    });

    it('ships only the foreground-safe permission baseline on Android', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['android']['permissions'])->toContain(
            'android.permission.INTERNET',
            'android.permission.ACCESS_FINE_LOCATION',
            'android.permission.ACCESS_COARSE_LOCATION',
        );

        // Background / foreground-service permissions are OPT-IN — the
        // post_compile hook layers them in from config. They must never ship
        // statically, or a foreground-only app (e.g. one-shot getCurrentPosition)
        // trips Google Play's FGS / background-location declarations.
        expect($manifest['android']['permissions'])->not->toContain('android.permission.ACCESS_BACKGROUND_LOCATION');
        expect($manifest['android']['permissions'])->not->toContain('android.permission.FOREGROUND_SERVICE');
        expect($manifest['android']['permissions'])->not->toContain('android.permission.FOREGROUND_SERVICE_LOCATION');
        expect($manifest['android']['permissions'])->not->toContain('android.permission.RECEIVE_BOOT_COMPLETED');

        expect($manifest['android'])->not->toHaveKey('services');
        expect($manifest['android'])->not->toHaveKey('receivers');
    });

    it('keeps the iOS location background mode opt-in', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        // No static `location` background mode / Always string — the hook adds
        // them only when the developer opts in.
        expect($manifest['ios']['background_modes'])->toBe([]);
        expect($manifest['ios']['info_plist'])->not->toHaveKey('NSLocationAlwaysAndWhenInUseUsageDescription');
        expect($manifest['ios']['init_function'])->toBe('geolocationPluginInit');
    });

    it('registers the post_compile manifest hook', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['hooks']['post_compile'])->toBe('nativephp:geolocation:configure-manifest');
    });

    it('prompts for permission from getCurrentPosition on both platforms', function () {
        // A one-shot getCurrentPosition() must request authorization when it
        // hasn't been asked yet, instead of hard-failing — otherwise it never
        // shows the system dialog and silently errors until the app happens to
        // call requestPermissions() separately. iOS and Android must match.
        $ios = file_get_contents($this->pluginPath.'/resources/ios/GeolocationFunctions.swift');
        expect($ios)
            ->toContain('if authStatus == .notDetermined {')
            ->toContain('pendingCurrentLocation = true')
            ->toContain('requestWhenInUseAuthorization()');

        $android = file_get_contents($this->pluginPath.'/resources/android/GeolocationCoordinator.kt');
        expect($android)
            ->toContain('pendingLocationRequest = PendingLocationRequest(')
            ->toContain('getCurrentPosition awaiting permission prompt');
    });

    it('never emits a permission result while the iOS dialog is still undecided', function () {
        // locationManagerDidChangeAuthorization fires with .notDetermined when
        // requestWhenInUseAuthorization() presents the dialog — BEFORE the user
        // answers. Emitting PermissionRequestResult there consumes the app's
        // one-shot callback with a premature "not_determined" (rendered as a
        // denial while the dialog is on screen) and drops the real answer.
        // Verified on-device via console capture; the result send must sit
        // behind the same .notDetermined guard the watch replays use.
        $ios = file_get_contents($this->pluginPath.'/resources/ios/GeolocationFunctions.swift');

        $delegate = substr($ios, strpos($ios, 'func locationManagerDidChangeAuthorization'));
        $sendPos = strpos($delegate, 'LaravelBridge.shared.send?(pendingPermissionEventClass');
        $guardPos = strpos($delegate, 'if manager.authorizationStatus != .notDetermined {');

        expect($guardPos)->not->toBeFalse()
            ->and($sendPos)->not->toBeFalse()
            ->and($guardPos)->toBeLessThan($sendPos);

        // One result per request: the pending id is consumed with the answer.
        expect($delegate)->toContain('pendingPermissionRequestId = nil');
    });

    it('registers the map components with NativePHP-cased classes that exist', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        $types = array_column($manifest['components'], 'type');
        expect($types)->toContain('map', 'map_marker');

        foreach ($manifest['components'] as $component) {
            foreach (['element', 'blade'] as $key) {
                expect($component[$key])->toStartWith('NativePHP\\Geolocation\\');

                // FQCN → file under src/ (PSR-4 root NativePHP\Geolocation\ => src/)
                $relative = str_replace(['NativePHP\\Geolocation\\', '\\'], ['', '/'], $component[$key]);
                expect(file_exists($this->pluginPath.'/src/'.$relative.'.php'))->toBeTrue();
            }
            expect(isset($component['android_renderer']) || isset($component['ios_renderer']))->toBeTrue();
        }
    });

    it('declares the streaming event', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['events'])->toContain(
            'Native\\Mobile\\Events\\Geolocation\\LocationReceived',
            'Native\\Mobile\\Events\\Geolocation\\LocationUpdated',
            'NativePHP\\Geolocation\\Events\\AppEnteredForeground',
        );

        expect(file_exists($this->pluginPath.'/src/Events/AppEnteredForeground.php'))->toBeTrue();
    });

    it('provides the foreground event payload', function () {
        require_once $this->pluginPath.'/src/Events/AppEnteredForeground.php';

        $event = new AppEnteredForeground(
            id: 'patrol-trail',
            timestamp: 1784156400000,
        );

        expect($event->id)->toBe('patrol-trail')
            ->and($event->timestamp)->toBe(1784156400000);
    });
});

describe('Native Code', function () {
    it('has matching bridge function classes in native code', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        // Bridge functions are spread across several native files
        // (GeolocationFunctions + BackgroundWatchFunctions) — check the
        // concatenated platform sources.
        $kotlinContent = implode('', array_map('file_get_contents', glob($this->pluginPath.'/resources/android/*.kt')));
        $swiftContent = implode('', array_map('file_get_contents', glob($this->pluginPath.'/resources/ios/*.swift')));

        foreach ($manifest['bridge_functions'] as $function) {
            if (isset($function['android'])) {
                $parts = explode('.', $function['android']);
                expect($kotlinContent)->toContain('class '.end($parts));
            }

            if (isset($function['ios'])) {
                $parts = explode('.', $function['ios']);
                expect($swiftContent)->toContain('class '.end($parts));
            }
        }
    });

    it('has renderer symbols matching the component manifest entries', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        $kotlinContent = implode('', array_map('file_get_contents', glob($this->pluginPath.'/resources/android/*.kt')));
        $swiftContent = implode('', array_map('file_get_contents', glob($this->pluginPath.'/resources/ios/*.swift')));

        foreach ($manifest['components'] as $component) {
            if (isset($component['android_renderer'])) {
                $parts = explode('.', $component['android_renderer']);
                expect($kotlinContent)->toContain('object '.end($parts));
            }
            if (isset($component['ios_renderer'])) {
                expect($swiftContent)->toContain('struct '.$component['ios_renderer']);
            }
        }
    });

    it('never emits live watch events without a foreground gate', function () {
        // A background-relaunched process has no PHP runtime or element
        // region; an ungated event write can kill it on its first fix
        // (found the hard way on-device). Both recorders must gate live
        // emission on visible UI.
        $service = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');
        $recorder = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');

        expect($service)->toContain('uiVisible()');
        expect($recorder)->toContain('applicationState == .active');
    });

    it('streams watch events through the element bridge only', function () {
        $kotlin = file_get_contents($this->pluginPath.'/resources/android/GeolocationCoordinator.kt');
        $swift = file_get_contents($this->pluginPath.'/resources/ios/GeolocationFunctions.swift');

        // Streaming must NOT ride the webview-JS + HTTP POST dispatch — one
        // POST per GPS fix boots a full Laravel request and floods the event
        // channel. See dispatchStream()/emitStream().
        expect($kotlin)->toContain('fun dispatchStream');
        expect($swift)->toContain('func emitStream');
    });

    it('emits a foreground event only after the app was backgrounded', function () {
        $service = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');
        $recorder = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');

        foreach ([$service, $recorder] as $source) {
            expect($source)
                ->toContain('appWasBackgrounded')
                ->toContain('AppEnteredForeground');
        }

        expect($service)
            ->toContain('NativePHPLifecycle.Events.ON_PAUSE')
            ->toContain('NativePHPLifecycle.Events.ON_RESUME')
            ->toContain('appWasBackgrounded = true')
            ->toContain('notifyAppEnteredForeground = Runnable { emitAppEnteredForeground() }')
            ->toContain('postDelayed(notifyAppEnteredForeground, 500L)')
            ->toContain('removeCallbacks(notifyAppEnteredForeground)');
        expect($recorder)
            ->toContain('UIApplication.didEnterBackgroundNotification')
            ->toContain('UIApplication.didBecomeActiveNotification');
    });

    it('drains the background buffer before invoking the foreground hook', function () {
        $trait = file_get_contents($this->pluginPath.'/src/Concerns/TracksBackgroundLocation.php');

        expect($trait)->toContain('AppEnteredForeground::class');
        expect(strpos($trait, '$this->syncBackgroundWatch();'))
            ->toBeLessThan(strpos($trait, '$this->onAppEnteredForeground();'));
    });
});

describe('Position Sharing', function () {
    it('exposes the share API on the plugin PHP layer', function () {
        $builder = file_get_contents($this->pluginPath.'/src/PendingSharedLocationWatch.php');
        $geolocation = file_get_contents($this->pluginPath.'/src/Geolocation.php');
        $provider = file_get_contents($this->pluginPath.'/src/GeolocationServiceProvider.php');

        expect($builder)->toContain('public function share(');
        expect($builder)->toContain('extends PendingLocationWatch');
        // share() must force background: a beacon outlives the screen.
        expect($builder)->toContain('return $this->background()');

        expect($geolocation)->toContain('public function sharePosition(');
        expect($geolocation)->toContain('extends BaseGeolocation');

        // The subclass must be bound to the CORE container key or the
        // Geolocation facade never resolves it.
        expect($provider)->toContain('new ExtendedGeolocation');
    });

    it('sends every share option through the StartBackgroundWatch payload', function () {
        $builder = file_get_contents($this->pluginPath.'/src/PendingSharedLocationWatch.php');

        expect($builder)->toContain('Geolocation.StartBackgroundWatch');
        foreach (["'shareUrl'", "'shareToken'", "'shareIntervalMs'", "'shareExpiresAt'", "'stopWatchOnExpiry'"] as $key) {
            expect($builder)->toContain($key);
        }
    });

    it('plumbs the background indicator option through to iOS', function () {
        $builder = file_get_contents($this->pluginPath.'/src/PendingSharedLocationWatch.php');
        $ios = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');

        expect($builder)->toContain('public function backgroundIndicator(')
            ->and($builder)->toContain("'showsBackgroundIndicator'");

        // The Swift side must parse it, persist it (breadcrumb relaunches
        // resume from the persisted config), and apply it to the manager.
        expect($ios)->toContain('showsBackgroundIndicator: parameters["showsBackgroundIndicator"] as? Bool ?? false')
            ->and($ios)->toContain('"showsBackgroundIndicator": config.showsBackgroundIndicator')
            ->and($ios)->toContain('showsBackgroundIndicator: stored["showsBackgroundIndicator"] as? Bool ?? false')
            ->and($ios)->toContain('manager.showsBackgroundLocationIndicator = config.showsBackgroundIndicator');
    });

    it('buffers altitude on both platforms', function () {
        $ios = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');
        $android = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');

        expect($ios)->toContain('if location.verticalAccuracy > 0 { payload["altitude"] = location.altitude }')
            ->and($android)->toContain('if (fix.hasAltitude()) put("altitude", fix.altitude)');
    });

    it('fails closed on invalid endpoints and expiry values', function () {
        $builder = file_get_contents($this->pluginPath.'/src/PendingSharedLocationWatch.php');
        $android = file_get_contents($this->pluginPath.'/resources/android/BackgroundWatchFunctions.kt');
        $ios = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');

        expect($builder)
            ->toContain("['http', 'https']")
            ->toContain('new DateTimeImmutable($expiresAt)')
            ->toContain('expiresAt must be a valid date-time or null');

        // Native parsing is a defensive backstop for stale/legacy config:
        // invalid non-null expiry maps to epoch+1ms, which is already expired.
        expect($android)->toContain('sharing disabled')->toContain('1L');
        expect($ios)->toContain('sharing disabled')->toContain('return 1');
    });

    it('implements the share uploader on both platforms', function () {
        $service = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');
        $bridge = file_get_contents($this->pluginPath.'/resources/android/BackgroundWatchFunctions.kt');
        $recorder = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');

        // Config + persistence carry the share fields (what makes the
        // beacon survive sticky restarts / reboots / relaunches).
        foreach (['shareUrl', 'shareToken', 'shareIntervalMs', 'stopWatchOnExpiry'] as $field) {
            expect($service)->toContain($field);
            expect($recorder)->toContain($field);
        }
        expect($bridge)->toContain('shareExpiresAt');
        expect($recorder)->toContain('shareExpiresAt');

        // The wire contract: bearer auth + single-latest-point body.
        expect($service)->toContain('Bearer $it');
        expect($recorder)->toContain('Bearer \(token)');
        expect($service)->toContain('"points"');
        expect($recorder)->toContain('["points": [point]]');
    });

    it('never runs share HTTP on the location worker or main thread', function () {
        // Android: a slow POST must not delay fix processing — uploads go
        // through a dedicated executor.
        $service = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');
        expect($service)->toContain('uploader.execute');
        expect($service)->toContain('newSingleThreadExecutor');

        // iOS: assert the async URLSession API (never a synchronous wait).
        $recorder = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');
        expect($recorder)->toContain('URLSession.shared.dataTask');
    });

    it('reports share state from BackgroundWatchStatus on both platforms', function () {
        $bridge = file_get_contents($this->pluginPath.'/resources/android/BackgroundWatchFunctions.kt');
        $service = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');
        $recorder = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');

        foreach (['shareActive', 'lastShareAt', 'lastShareStatus'] as $field) {
            expect($bridge)->toContain($field);
            expect($recorder)->toContain($field);
        }

        // Expiry remains observable even when it stops the entire watch.
        expect($service)->toContain('preserveShareResult = true');
        expect($recorder)->toContain('preserveShareResult: true');
        expect($bridge)->toContain('lastShare?.status == LocationWatchService.SHARE_STATUS_EXPIRED');
        expect($recorder)->toContain('share.status == BackgroundLocationRecorder.shareStatusExpired');
    });

    it('schedules Android intervals relative to the immediate first share', function () {
        $service = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');

        expect($service)->toContain('shareLatestFix(config)')
            ->toContain('scheduleNextShare(config)')
            ->toContain('worker.postDelayed(tick, config.shareIntervalMs)');
    });

    it('keeps buffering before beaconing (buffer is the source of truth)', function () {
        // The share hook must come AFTER the buffer append in the fix path
        // on both platforms.
        $service = file_get_contents($this->pluginPath.'/resources/android/LocationWatchService.kt');
        expect(strpos($service, 'appendToBuffer(config.id, payload)'))
            ->toBeLessThan(strpos($service, 'shareLatestFix(config)'));

        $recorder = file_get_contents($this->pluginPath.'/resources/ios/BackgroundWatchFunctions.swift');
        expect(strpos($recorder, 'append(payload, watchId: config.id)'))
            ->toBeLessThan(strpos($recorder, 'shareIfDue(location)'));
    });

    it('documents the share feature', function () {
        expect(file_exists($this->pluginPath.'/SHARE_README.md'))->toBeTrue();

        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        $start = null;
        foreach ($manifest['bridge_functions'] as $function) {
            if ($function['name'] === 'Geolocation.StartBackgroundWatch') {
                $start = $function;
            }
        }

        expect($start)->not->toBeNull();
        expect($start['description'])->toContain('shareUrl');

        $documentation = file_get_contents($this->pluginPath.'/SHARE_README.md');
        expect($documentation)
            ->toContain('AppEnteredForeground')
            ->toContain('onAppEnteredForeground')
            ->toContain('attachBackgroundWatch()');
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['require']['php'])->toBe('^8.3');
    });
});
