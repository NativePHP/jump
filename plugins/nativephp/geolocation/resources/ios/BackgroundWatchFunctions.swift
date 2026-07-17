import Foundation
import CoreLocation
import UIKit

// MARK: - Plugin init

/// Called from the generated plugin registration at app launch. Re-arms a
/// background watch that survived the process (iOS relaunched us for a
/// significant-change fix, or the user simply reopened the app) — the iOS
/// analogue of Android's boot receiver + START_STICKY.
func geolocationPluginInit() {
    BackgroundLocationRecorder.shared.resumeIfPersisted()
}

// MARK: - Background Watch Bridge Functions

/// Background-watch bridge functions.
/// Namespace: "Geolocation.StartBackgroundWatch" / "StopBackgroundWatch" /
/// "DrainWatchBuffer" / "BackgroundWatchStatus".
///
/// Unlike the session-backed foreground watch (WatchPosition), these drive
/// [BackgroundLocationRecorder] — a CLLocationManager configured for
/// background delivery whose fixes are buffered to disk, so the stream
/// outlives the screen, the PHP runtime, and (via significant-change
/// relaunch) the process itself.
enum BackgroundWatchFunctions {

    /// Start a background location watch.
    /// Parameters:
    ///   - id: string (required) - Watch identifier; buffered fixes and live events carry it
    ///   - event: string (optional) - Event class dispatched per live update
    ///   - fineAccuracy: boolean (optional) - Best accuracy vs hundred-meter
    ///   - minDistance: double (optional) - Meters moved before another update
    ///   - interval: int (optional) - Ignored on iOS (CoreLocation is event-driven)
    ///   - showsBackgroundIndicator: boolean (optional) - Show the prominent blue
    ///     background-location indicator while recording (default false)
    ///   - shareUrl: string (optional) - POST the latest fix here on an interval (live-location beacon)
    ///   - shareToken: string (optional) - Bearer token for the share POSTs
    ///   - shareIntervalMs: number (optional) - Ms between share POSTs (default 60000)
    ///   - shareExpiresAt: string (optional) - ISO-8601 moment after which sharing stops
    ///   - stopWatchOnExpiry: boolean (optional) - Also stop recording at expiry (buffer kept)
    class Start: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String, !id.isEmpty else {
                return BridgeResponse.error(
                    code: "geolocation.missing_id",
                    message: "StartBackgroundWatch requires an id"
                )
            }

            // A build without the location background mode records fine in
            // the Simulator (it doesn't enforce the mode) and silently goes
            // dark on hardware the moment the app leaves the foreground.
            // Fail the bridge call instead of degrading invisibly.
            if !BackgroundLocationRecorder.hasLocationBackgroundMode {
                return BridgeResponse.error(
                    code: "geolocation.missing_background_mode",
                    message: "Info.plist UIBackgroundModes is missing \"location\" — "
                        + "declare ios.background_modes [\"location\"] and rebuild"
                )
            }

            let shareIntervalMs = max(
                5_000,
                (parameters["shareIntervalMs"] as? Int)
                    ?? (parameters["shareIntervalMs"] as? Double).map(Int.init) ?? 60_000
            )

            let config = BackgroundLocationRecorder.Config(
                id: id,
                eventClass: parameters["event"] as? String
                    ?? "Native\\Mobile\\Events\\Geolocation\\LocationUpdated",
                fineAccuracy: parameters["fineAccuracy"] as? Bool ?? false,
                minDistance: (parameters["minDistance"] as? Double)
                    ?? (parameters["minDistance"] as? Int).map(Double.init) ?? 0,
                showsBackgroundIndicator: parameters["showsBackgroundIndicator"] as? Bool ?? false,
                shareUrl: (parameters["shareUrl"] as? String).flatMap { $0.isEmpty ? nil : $0 },
                shareToken: (parameters["shareToken"] as? String).flatMap { $0.isEmpty ? nil : $0 },
                shareIntervalMs: shareIntervalMs,
                shareExpiresAtMs: BackgroundLocationRecorder.parseIsoToEpochMs(
                    parameters["shareExpiresAt"] as? String
                ),
                stopWatchOnExpiry: parameters["stopWatchOnExpiry"] as? Bool ?? false
            )

            // A new user-initiated session must not expose the previous
            // session's terminal/result state.
            BackgroundLocationRecorder.clearShareResult()
            DispatchQueue.main.async {
                BackgroundLocationRecorder.shared.start(config)
            }

            return ["success": true]
        }
    }

    /// Stop the background watch and clear its persisted config.
    /// Parameters:
    ///   - id: string (optional) - When given with clearBuffer, also deletes that watch's buffer
    ///   - clearBuffer: boolean (optional) - Delete the buffered fixes too (default false)
    class Stop: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String
            let clearBuffer = parameters["clearBuffer"] as? Bool ?? false

            DispatchQueue.main.async {
                BackgroundLocationRecorder.shared.stop()
            }
            if clearBuffer, let id = id, !id.isEmpty {
                try? FileManager.default.removeItem(
                    at: BackgroundLocationRecorder.bufferURL(watchId: id)
                )
            }

            return ["success": true]
        }
    }

    /// Drain buffered fixes from a byte cursor.
    /// Parameters:
    ///   - id: string (required) - The watch whose buffer to read
    ///   - cursor: number (optional) - Byte offset from a previous drain (default 0)
    /// Returns: { fixes: [...], cursor: <next byte offset>, size: <buffer bytes> }
    class Drain: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String, !id.isEmpty else {
                return BridgeResponse.error(
                    code: "geolocation.missing_id",
                    message: "DrainWatchBuffer requires an id"
                )
            }

            let url = BackgroundLocationRecorder.bufferURL(watchId: id)
            guard let data = try? Data(contentsOf: url) else {
                return ["fixes": [], "cursor": 0, "size": 0]
            }

            var cursor = (parameters["cursor"] as? Int) ?? 0
            if cursor < 0 || cursor > data.count { cursor = 0 }

            var fixes: [[String: Any]] = []
            if let chunk = String(data: data.subdata(in: cursor..<data.count), encoding: .utf8) {
                for line in chunk.split(separator: "\n") {
                    if let lineData = line.data(using: .utf8),
                       let fix = try? JSONSerialization.jsonObject(with: lineData) as? [String: Any] {
                        fixes.append(fix)
                    }
                }
            }

            return ["fixes": fixes, "cursor": data.count, "size": data.count]
        }
    }

    /// Drop buffered fixes BEFORE a byte offset — the reclaim half of the
    /// drain → persist/upload → trim cycle. Pass the cursor a successful
    /// drain returned; offsets rebase to 0 (caller resets its cursor).
    /// Parameters:
    ///   - id: string (required)
    ///   - upTo: number (required) - Byte offset from a prior drain
    /// Returns: { success: true, size: <remaining bytes> }
    class Trim: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String, !id.isEmpty else {
                return BridgeResponse.error(
                    code: "geolocation.missing_id",
                    message: "TrimWatchBuffer requires an id"
                )
            }
            let upTo = (parameters["upTo"] as? Int) ?? 0

            let url = BackgroundLocationRecorder.bufferURL(watchId: id)
            guard upTo > 0, let data = try? Data(contentsOf: url) else {
                let size = (try? Data(contentsOf: url).count) ?? 0
                return ["success": true, "size": size]
            }

            let remainder = upTo >= data.count ? Data() : data.subdata(in: upTo..<data.count)
            // Atomic write so a concurrent append lands before or after
            // the swap, never inside a half-written file.
            do {
                try remainder.write(to: url, options: .atomic)
                try? FileManager.default.setAttributes(
                    [.protectionKey: FileProtectionType.none], ofItemAtPath: url.path
                )
            } catch {
                return BridgeResponse.error(
                    code: "geolocation.trim_failed",
                    message: "Could not replace buffer file: \(error.localizedDescription)"
                )
            }

            return ["success": true, "size": remainder.count]
        }
    }

    /// Report the persisted background watch, if any.
    /// Returns: { active: bool, id?, event?, minDistance?, fineAccuracy?, bufferBytes?,
    ///            shareActive?, shareIntervalMs?, shareExpiresAt?, lastShareAt?, lastShareStatus? }
    /// lastShareStatus: HTTP code, 0 = network failure, -1 = expired.
    class Status: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let config = BackgroundLocationRecorder.persistedConfig() else {
                if let share = BackgroundLocationRecorder.lastShareResult(),
                   share.status == BackgroundLocationRecorder.shareStatusExpired {
                    return [
                        "active": false,
                        "id": share.watchId,
                        "shareActive": false,
                        "lastShareStatus": share.status,
                    ]
                }
                return ["active": false]
            }

            let url = BackgroundLocationRecorder.bufferURL(watchId: config.id)
            let size = (try? Data(contentsOf: url).count) ?? 0

            // Environment gates that silently stop background delivery on
            // hardware — surfaced so apps can warn instead of going dark.
            // backgroundRefreshStatus is main-thread state; bridge calls
            // run on the PHP thread, so hop synchronously.
            var backgroundRefresh = "unknown"
            var lowPower = false
            DispatchQueue.main.sync {
                backgroundRefresh = BackgroundLocationRecorder.backgroundRefreshDescription
                lowPower = ProcessInfo.processInfo.isLowPowerModeEnabled
            }

            var result: [String: Any] = [
                "active": true,
                "id": config.id,
                "event": config.eventClass,
                "minDistance": config.minDistance,
                "fineAccuracy": config.fineAccuracy,
                "bufferBytes": size,
                // Terminated-app tracking needs "always": without it iOS
                // never relaunches the app for significant-change events,
                // so a killed watch records nothing until manually reopened.
                "authorization": BackgroundLocationRecorder.shared.authorizationDescription,
                "backgroundMode": BackgroundLocationRecorder.hasLocationBackgroundMode,
                "lowPowerMode": lowPower,
                "backgroundRefresh": backgroundRefresh,
                "shareActive": config.isSharing,
                "shareIntervalMs": config.shareIntervalMs,
            ]
            if config.shareExpiresAtMs > 0 {
                result["shareExpiresAt"] = BackgroundLocationRecorder.isoFormatter.string(
                    from: Date(timeIntervalSince1970: Double(config.shareExpiresAtMs) / 1000)
                )
            }
            if let share = BackgroundLocationRecorder.lastShareResult(), share.watchId == config.id {
                if share.atMs > 0 {
                    result["lastShareAt"] = BackgroundLocationRecorder.isoFormatter.string(
                        from: Date(timeIntervalSince1970: Double(share.atMs) / 1000)
                    )
                }
                result["lastShareStatus"] = share.status
            }

            return result
        }
    }
}

// MARK: - Background Location Recorder

/// One background-capable location stream. Owns a CLLocationManager set up
/// for background delivery and double-writes every fix:
///  - to the append-only JSONL buffer (source of truth; PHP drains by cursor)
///  - to the live event stream, best-effort, for real-time UI while the
///    runtime is up (same emitStream path as the foreground watch).
///
/// The config persists in UserDefaults so a relaunch (significant-change
/// wake, or the user reopening the app) resumes recording via
/// `geolocationPluginInit` → `resumeIfPersisted()`.
final class BackgroundLocationRecorder: NSObject, CLLocationManagerDelegate {
    private static let appEnteredForegroundEvent = "NativePHP\\Geolocation\\Events\\AppEnteredForeground"

    static let shared = BackgroundLocationRecorder()

    struct Config {
        let id: String
        let eventClass: String
        let fineAccuracy: Bool
        let minDistance: Double
        /// Show iOS's prominent background-location indicator (blue pill)
        /// while recording. Hidden by default (Life360 parity) — but hidden
        /// sessions have been observed suspended in the background on
        /// iOS 26 despite Always authorization, so callers who need
        /// continuous delivery should opt in.
        var showsBackgroundIndicator: Bool = false
        // Share (live-location beacon) — nil shareUrl means recording only.
        var shareUrl: String? = nil
        var shareToken: String? = nil
        var shareIntervalMs: Int = 60_000
        var shareExpiresAtMs: Int = 0
        var stopWatchOnExpiry: Bool = false

        var isSharing: Bool { !(shareUrl ?? "").isEmpty }

        /// The same config with the beacon removed (recording only).
        var withoutShare: Config {
            var copy = self
            copy.shareUrl = nil
            copy.shareToken = nil
            copy.shareExpiresAtMs = 0
            copy.stopWatchOnExpiry = false
            return copy
        }
    }

    private let manager = CLLocationManager()
    private var appWasBackgrounded = false
    private var config: Config?
    /// Set while we wait on the Always-authorization prompt; replayed from
    /// the authorization delegate once the user answers.
    private var pendingConfig: Config?
    /// Share (live-location beacon) state. iOS has no background timers, so
    /// uploads are throttled per-fix: each fix that arrives ≥ interval after
    /// the last upload triggers one POST. CoreLocation IS the clock — while
    /// the recorder runs, fixes keep arriving, so the beacon keeps beating.
    private var lastShareAtMs: Int = 0
    private var firstShareSent = false
    private var shareInFlight = false
    private var shareTerminated = false
    /// Center of the currently planted breadcrumb geofence (see
    /// replantBreadcrumb).
    private var breadcrumbCenter: CLLocation?

    private static let breadcrumbId = "nphp-breadcrumb"
    private static let breadcrumbRadius: CLLocationDistance = 100

    override private init() {
        super.init()
        manager.delegate = self

        // Forensic breadcrumbs: stamp app lifecycle transitions into the
        // buffer (coordinate-less markers, filtered out by consumers).
        // Fix timestamps relative to these markers show the exact moment
        // delivery stops or resumes around backgrounding — the data that
        // separates "iOS suspended us" from every other hypothesis.
        let center = NotificationCenter.default
        center.addObserver(
            self, selector: #selector(appLifecycleMarker(_:)),
            name: UIApplication.didEnterBackgroundNotification, object: nil
        )
        center.addObserver(
            self, selector: #selector(appLifecycleMarker(_:)),
            name: UIApplication.didBecomeActiveNotification, object: nil
        )
        center.addObserver(
            self, selector: #selector(appLifecycleMarker(_:)),
            name: UIApplication.willTerminateNotification, object: nil
        )
    }

    @objc private func appLifecycleMarker(_ note: Notification) {
        guard let config = config else { return }
        let name: String
        switch note.name {
        case UIApplication.didEnterBackgroundNotification:
            name = "entered_background"
            appWasBackgrounded = true
        case UIApplication.didBecomeActiveNotification:
            name = "became_active"
            if appWasBackgrounded {
                appWasBackgrounded = false
                DispatchQueue.main.async {
                    LocationWatchSession.emitStream(Self.appEnteredForegroundEvent, [
                        "id": config.id,
                        "timestamp": Int(Date().timeIntervalSince1970 * 1000),
                    ])
                }
            }
        case UIApplication.willTerminateNotification: name = "will_terminate"
        default: return
        }
        append([
            "marker": name,
            "authorization": authorizationDescription,
            "lowPowerMode": ProcessInfo.processInfo.isLowPowerModeEnabled,
            "backgroundRefresh": Self.backgroundRefreshDescription,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "id": config.id,
        ], watchId: config.id)
    }

    static var backgroundRefreshDescription: String {
        switch UIApplication.shared.backgroundRefreshStatus {
        case .available: return "available"
        case .denied: return "denied"
        case .restricted: return "restricted"
        @unknown default: return "unknown"
        }
    }

    func start(_ config: Config) {
        let status = manager.authorizationStatus

        // Background delivery wants Always. Prompt through the ladder —
        // notDetermined asks for WhenInUse first (iOS requires the
        // escalation order), whenInUse escalates to Always. Recording
        // still starts under WhenInUse: fixes flow while the app is up
        // and (with the background mode + indicator) backgrounded, just
        // not after a relaunch-from-terminated.
        if status == .notDetermined {
            pendingConfig = config
            manager.requestWhenInUseAuthorization()
            return
        }
        if status == .authorizedWhenInUse {
            manager.requestAlwaysAuthorization()
        }
        guard status == .authorizedWhenInUse || status == .authorizedAlways else {
            print("[Geolocation] Background watch \(config.id) denied - status: \(status.rawValue)")
            LocationWatchSession.emitStream(config.eventClass, [
                "success": false,
                "timestamp": Int(Date().timeIntervalSince1970 * 1000),
                "error": "Location permission not granted",
                "id": config.id,
            ])
            return
        }

        self.config = config
        Self.persist(config)

        // Fresh share session: beacon the first fix immediately so the
        // server learns the position seconds after start, not a full
        // interval later.
        firstShareSent = false
        lastShareAtMs = 0
        shareTerminated = false

        manager.desiredAccuracy = config.fineAccuracy
            ? kCLLocationAccuracyBest
            : kCLLocationAccuracyHundredMeters
        manager.distanceFilter = config.minDistance > 0 ? config.minDistance : kCLDistanceFilterNone
        // .other (the default) lets iOS duty-cycle the GPS aggressively in
        // the background, which reads as gaps in the trail on hardware.
        // .otherNavigation keeps the radio hot for continuous tracking
        // across walking and driving. (The Simulator ignores all of this,
        // which is why gaps never reproduce there.)
        manager.activityType = .otherNavigation
        manager.pausesLocationUpdatesAutomatically = false
        // Default false = no prominent blue pill / Dynamic Island indicator
        // while recording in the background (Life360 parity); the OS still
        // shows the standard status-bar location arrow. Callers opt in via
        // showsBackgroundIndicator — field data (iOS 26.5, Always-authorized
        // session, 2026-07-16) showed an indicator-less session being
        // suspended in the background and surviving only on breadcrumb
        // wakes, so the visible indicator is the safe choice when
        // continuous delivery matters.
        manager.showsBackgroundLocationIndicator = config.showsBackgroundIndicator
        // Guarded: setting this without the location background mode is
        // rejected on hardware (and has historically thrown). The bridge
        // Start already errors in that case; this keeps a stale persisted
        // watch from crash-looping a relaunched process on a bad build.
        if Self.hasLocationBackgroundMode {
            manager.allowsBackgroundLocationUpdates = true
        } else {
            print("[Geolocation] UIBackgroundModes missing 'location' — foreground-only recording")
        }

        manager.startUpdatingLocation()
        // Every wake source we can register relaunches a terminated app so
        // the recorder can re-arm (the iOS analogue of Android's boot
        // receiver + START_STICKY). Per Apple's Location Awareness guide,
        // region monitoring and the significant-change service are "the
        // only way to have your app relaunched automatically". SLC fires
        // on ~500m/cell handoff at a several-minute cadence; visits fire
        // on arrive/leave; the breadcrumb geofence (planted per fix in
        // didUpdateLocations) exits at ~100m for the tightest wake — the
        // Life360-style leapfrog. Field data: SLC has never resurrected a
        // force-quit app in our tests — the breadcrumb is the load-bearing
        // relaunch path, which makes its arming window critical.
        manager.startMonitoringSignificantLocationChanges()
        manager.startMonitoringVisits()

        // Plant the first crumb IMMEDIATELY from the cached last-known
        // location instead of waiting for a fresh fix. Region registration
        // and initial inside-state determination in locationd are
        // asynchronous and slow; every second the crumb isn't armed is a
        // second in which a force-quit permanently kills tracking (field
        // data: kills ≤30s after start never resurrected; a mature
        // session's kill resurrected in 15s). The next fresh fix replants
        // at true position.
        if let cached = manager.location {
            replantBreadcrumb(at: cached, force: true)
        }

        // Buffer a diagnostic marker (no coordinates — consumers filter on
        // latitude) so a drained trail shows exactly when and in what state
        // recording re-armed: gaps that END with an "armed" marker in the
        // background are kill/relaunch latency; gaps without one mean the
        // process was never woken (almost always missing Always).
        append([
            "marker": "armed",
            "authorization": authorizationDescription,
            "appState": Self.appStateDescription,
            "backgroundMode": Self.hasLocationBackgroundMode,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "id": config.id,
        ], watchId: config.id)

        print("[Geolocation] Background watch \(config.id) recording (auth: \(authorizationDescription))")
    }

    func stop(preserveShareResult: Bool = false) {
        shareTerminated = true
        manager.stopUpdatingLocation()
        manager.stopMonitoringSignificantLocationChanges()
        manager.stopMonitoringVisits()
        for region in manager.monitoredRegions where region.identifier == Self.breadcrumbId {
            manager.stopMonitoring(for: region)
        }
        breadcrumbCenter = nil
        config = nil
        pendingConfig = nil
        firstShareSent = false
        lastShareAtMs = 0
        Self.clearPersisted(clearShareResult: !preserveShareResult)
        print("[Geolocation] Background watch stopped")
    }

    /**
     * Life360-style leapfrog: keep one exit-geofence planted on the last
     * fix. Monitored regions live in the SYSTEM (locationd), so crossing
     * one relaunches even a terminated app — and at ~100m it wakes far
     * tighter than significant-change's ~500m/minutes cadence. Each wake
     * re-arms the recorder (plugin init), fixes flow, and the next fix
     * replants the crumb further down the road.
     */
    private func replantBreadcrumb(at location: CLLocation, force: Bool = false) {
        // Replant only after meaningful movement — region churn costs.
        if !force, let center = breadcrumbCenter, location.distance(from: center) < Self.breadcrumbRadius / 2 {
            return
        }
        breadcrumbCenter = location

        for region in manager.monitoredRegions where region.identifier == Self.breadcrumbId {
            manager.stopMonitoring(for: region)
        }
        let region = CLCircularRegion(
            center: location.coordinate,
            radius: Self.breadcrumbRadius,
            identifier: Self.breadcrumbId
        )
        region.notifyOnExit = true
        region.notifyOnEntry = false
        manager.startMonitoring(for: region)
        // Ask locationd to determine the inside/outside state NOW rather
        // than at its leisure — an exit can only fire from an established
        // "inside" state, and a force-quit freezes whatever state exists.
        manager.requestState(for: region)
    }

    // Region-registration forensics: these confirm (or deny) that the
    // crumb was durably armed in locationd before a kill — the difference
    // between "iOS declined to wake us" and "we never finished arming".
    func locationManager(_ manager: CLLocationManager, didStartMonitoringFor region: CLRegion) {
        guard region.identifier == Self.breadcrumbId, let config = config else { return }
        append([
            "marker": "breadcrumb_registered",
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "id": config.id,
        ], watchId: config.id)
    }

    func locationManager(_ manager: CLLocationManager, didDetermineState state: CLRegionState, for region: CLRegion) {
        guard region.identifier == Self.breadcrumbId, let config = config else { return }
        append([
            "marker": "breadcrumb_state",
            "state": state == .inside ? "inside" : (state == .outside ? "outside" : "unknown"),
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "id": config.id,
        ], watchId: config.id)
    }

    func locationManager(_ manager: CLLocationManager, monitoringDidFailFor region: CLRegion?, withError error: Error) {
        guard let config = config else { return }
        append([
            "marker": "breadcrumb_failed",
            "error": error.localizedDescription,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "id": config.id,
        ], watchId: config.id)
    }

    var authorizationDescription: String {
        switch manager.authorizationStatus {
        case .authorizedAlways: return "always"
        case .authorizedWhenInUse: return "whenInUse"
        case .denied: return "denied"
        case .restricted: return "restricted"
        case .notDetermined: return "notDetermined"
        @unknown default: return "unknown"
        }
    }

    static var hasLocationBackgroundMode: Bool {
        let modes = Bundle.main.object(forInfoDictionaryKey: "UIBackgroundModes") as? [String] ?? []
        return modes.contains("location")
    }

    private static var appStateDescription: String {
        switch UIApplication.shared.applicationState {
        case .active: return "active"
        case .background: return "background"
        case .inactive: return "inactive"
        @unknown default: return "unknown"
        }
    }

    /// Resume a persisted watch after relaunch. No-op when nothing persisted
    /// or the recorder is already running.
    func resumeIfPersisted() {
        guard config == nil, let persisted = Self.persistedConfig() else { return }
        start(persisted)
    }

    // MARK: CLLocationManagerDelegate

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        guard manager.authorizationStatus != .notDetermined else { return }

        // The armed marker can capture a STALE status: authorizationStatus
        // read synchronously at manager creation (cold launch) races the
        // XPC to locationd and can under-report (e.g. whenInUse on an
        // Always-granted app). This callback is the authoritative value —
        // stamp it so buffer forensics are trustworthy.
        if let config = config {
            append([
                "marker": "authorization",
                "authorization": authorizationDescription,
                "timestamp": Int(Date().timeIntervalSince1970 * 1000),
                "id": config.id,
            ], watchId: config.id)
        }

        if let pending = pendingConfig {
            pendingConfig = nil
            start(pending)
        }
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let config = config else { return }

        for location in locations {
            var payload: [String: Any] = [
                "success": true,
                "latitude": location.coordinate.latitude,
                "longitude": location.coordinate.longitude,
                "accuracy": location.horizontalAccuracy,
                "timestamp": Int(location.timestamp.timeIntervalSince1970 * 1000),
                "provider": "core_location",
                "error": false,
                "id": config.id,
            ]
            if location.speed >= 0 { payload["speed"] = location.speed }
            if location.course >= 0 { payload["heading"] = location.course }
            if location.verticalAccuracy > 0 { payload["altitude"] = location.altitude }

            append(payload, watchId: config.id)
            replantBreadcrumb(at: location)
            shareIfDue(location)

            // Live events are foreground sugar ONLY. In a background or
            // background-relaunched process there is no PHP runtime or
            // element region to receive them — poking the event channel
            // there is at best undefined and can kill the relaunched
            // process on its first fix, silencing killed-app recording.
            // (Android needed the identical gate.) The buffer above
            // carries the stream regardless.
            if UIApplication.shared.applicationState == .active {
                LocationWatchSession.emitStream(config.eventClass, payload)
            }
        }
    }

    // MARK: Share (live-location beacon)

    /// Beacon semantics: one POST of the LATEST fix per interval; a failed
    /// POST is dropped (the next fix is fresher), and the JSONL buffer
    /// remains the authoritative history. No retry queue by design.
    /// URLSession is independent of the Laravel bridge, so this is safe in
    /// headless relaunched processes where event emission is not.
    private func shareIfDue(_ location: CLLocation) {
        guard let config = config, config.isSharing else { return }

        let nowMs = Int(Date().timeIntervalSince1970 * 1000)

        if config.shareExpiresAtMs > 0, nowMs >= config.shareExpiresAtMs {
            handleShareExpiry(config)
            return
        }

        let due = !firstShareSent || nowMs - lastShareAtMs >= config.shareIntervalMs
        guard due, !shareInFlight else { return }
        firstShareSent = true
        lastShareAtMs = nowMs
        shareInFlight = true

        var point: [String: Any] = [
            "latitude": location.coordinate.latitude,
            "longitude": location.coordinate.longitude,
            "accuracy": location.horizontalAccuracy,
            "timestamp": Self.isoFormatter.string(from: location.timestamp),
        ]
        if location.speed >= 0 { point["speed"] = location.speed }
        if location.course >= 0 { point["heading"] = location.course }
        if location.verticalAccuracy > 0 { point["altitude"] = location.altitude }

        guard let url = URL(string: config.shareUrl ?? ""),
              let body = try? JSONSerialization.data(withJSONObject: ["points": [point]]) else {
            shareInFlight = false
            return
        }

        var request = URLRequest(url: url, timeoutInterval: 15)
        request.httpMethod = "POST"
        request.httpBody = body
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = config.shareToken, !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        URLSession.shared.dataTask(with: request) { [weak self] _, response, error in
            let status = (response as? HTTPURLResponse)?.statusCode ?? 0
            if let error = error {
                // Beacon dropped (offline, DNS, timeout…) — the next due fix sends fresher data.
                print("[Geolocation] Share POST dropped: \(error.localizedDescription)")
            } else {
                print("[Geolocation] Share POST -> \(status)")
            }
            DispatchQueue.main.async { [weak self] in
                guard let self = self else { return }
                defer { self.shareInFlight = false }
                guard !self.shareTerminated, self.config?.id == config.id else { return }
                Self.persistShareResult(
                    watchId: config.id,
                    atMs: Int(Date().timeIntervalSince1970 * 1000),
                    status: status
                )
            }
        }.resume()
    }

    /// Expiry: stop the beacon. With stopWatchOnExpiry the whole watch stops
    /// too — recording ends and the persisted config is cleared, but the
    /// buffer file is KEPT (frozen) for the app to drain on next open.
    /// Without it, recording continues; the persisted config is rewritten
    /// without the share fields so a relaunch doesn't resurrect an expired
    /// beacon.
    private func handleShareExpiry(_ expired: Config) {
        print("[Geolocation] Share for watch \(expired.id) expired")
        shareTerminated = true
        Self.persistShareResult(
            watchId: expired.id,
            atMs: 0,
            status: Self.shareStatusExpired
        )

        if expired.stopWatchOnExpiry {
            stop(preserveShareResult: true)
        } else {
            let recordingOnly = expired.withoutShare
            config = recordingOnly
            Self.persist(recordingOnly)
        }
    }

    static let shareStatusExpired = -1

    static let isoFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        return formatter
    }()

    /// ISO-8601 → epoch ms; 0 = no expiry, 1 = invalid (fail closed as expired).
    static func parseIsoToEpochMs(_ iso: String?) -> Int {
        guard let iso = iso, !iso.isEmpty else { return 0 }
        let fractional = ISO8601DateFormatter()
        fractional.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        guard let date = isoFormatter.date(from: iso) ?? fractional.date(from: iso) else {
            print("[Geolocation] Unparseable shareExpiresAt '\(iso)' — sharing disabled")
            return 1
        }
        return Int(date.timeIntervalSince1970 * 1000)
    }

    func locationManager(_ manager: CLLocationManager, didExitRegion region: CLRegion) {
        guard region.identifier == Self.breadcrumbId, let config = config else { return }

        // This callback may BE the wake event in a relaunched process
        // (plugin init already re-armed us). Stamp the forensic marker;
        // startUpdatingLocation is already live, and the next fix
        // replants the crumb.
        append([
            "marker": "breadcrumb_exit",
            "authorization": authorizationDescription,
            "appState": Self.appStateDescription,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "id": config.id,
        ], watchId: config.id)
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        // kCLErrorLocationUnknown is transient - CoreLocation keeps trying.
        if (error as NSError).code == CLError.locationUnknown.rawValue { return }
        print("[Geolocation] Background watch error: \(error.localizedDescription)")
    }

    // MARK: Buffer

    private func append(_ payload: [String: Any], watchId: String) {
        guard let data = try? JSONSerialization.data(withJSONObject: payload),
              var line = String(data: data, encoding: .utf8) else { return }
        line += "\n"

        let url = Self.bufferURL(watchId: watchId)
        if let handle = try? FileHandle(forWritingTo: url) {
            defer { try? handle.close() }
            _ = try? handle.seekToEnd()
            try? handle.write(contentsOf: Data(line.utf8))
        } else {
            // First write creates the file with NO data protection: a
            // significant-change relaunch after a device reboot can arrive
            // BEFORE first unlock, when protected files are unreadable —
            // recording must not fail in that window. The buffer is
            // already app-sandboxed and excluded from backups.
            FileManager.default.createFile(
                atPath: url.path,
                contents: Data(line.utf8),
                attributes: [.protectionKey: FileProtectionType.none]
            )
        }
    }

    static func bufferURL(watchId: String) -> URL {
        var base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("nativephp_location", isDirectory: true)
        if !FileManager.default.fileExists(atPath: base.path) {
            try? FileManager.default.createDirectory(at: base, withIntermediateDirectories: true)
        }
        // A location trail must not ride along in iCloud/device backups.
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try? base.setResourceValues(values)

        // Watch ids are UUIDs from PHP; sanitize anyway since this names a file.
        let safe = watchId.replacingOccurrences(
            of: "[^A-Za-z0-9_-]", with: "_", options: .regularExpression
        )
        return base.appendingPathComponent(safe + ".jsonl")
    }

    // MARK: Persistence

    private static let defaultsKey = "nativephp.geolocation.background_watch"
    private static let shareResultKey = "nativephp.geolocation.share_result"

    private static func persist(_ config: Config) {
        // NOTE: the bearer token is stored in plain UserDefaults — parity
        // with the config's other fields. Use short-lived tokens; Keychain
        // storage is a tracked follow-up.
        var stored: [String: Any] = [
            "id": config.id,
            "event": config.eventClass,
            "fineAccuracy": config.fineAccuracy,
            "minDistance": config.minDistance,
            "showsBackgroundIndicator": config.showsBackgroundIndicator,
            "shareIntervalMs": config.shareIntervalMs,
            "shareExpiresAtMs": config.shareExpiresAtMs,
            "stopWatchOnExpiry": config.stopWatchOnExpiry,
        ]
        if let shareUrl = config.shareUrl { stored["shareUrl"] = shareUrl }
        if let shareToken = config.shareToken { stored["shareToken"] = shareToken }
        UserDefaults.standard.set(stored, forKey: defaultsKey)
    }

    static func persistedConfig() -> Config? {
        guard let stored = UserDefaults.standard.dictionary(forKey: defaultsKey),
              let id = stored["id"] as? String else { return nil }
        return Config(
            id: id,
            eventClass: stored["event"] as? String
                ?? "Native\\Mobile\\Events\\Geolocation\\LocationUpdated",
            fineAccuracy: stored["fineAccuracy"] as? Bool ?? false,
            minDistance: stored["minDistance"] as? Double ?? 0,
            showsBackgroundIndicator: stored["showsBackgroundIndicator"] as? Bool ?? false,
            shareUrl: stored["shareUrl"] as? String,
            shareToken: stored["shareToken"] as? String,
            shareIntervalMs: stored["shareIntervalMs"] as? Int ?? 60_000,
            shareExpiresAtMs: stored["shareExpiresAtMs"] as? Int ?? 0,
            stopWatchOnExpiry: stored["stopWatchOnExpiry"] as? Bool ?? false
        )
    }

    private static func clearPersisted(clearShareResult: Bool = true) {
        UserDefaults.standard.removeObject(forKey: defaultsKey)
        if clearShareResult {
            UserDefaults.standard.removeObject(forKey: shareResultKey)
        }
    }

    /// Last share outcome, readable by BackgroundWatchStatus.
    /// Status 0 = network failure, -1 = expired.
    static func persistShareResult(watchId: String, atMs: Int, status: Int) {
        UserDefaults.standard.set(
            ["watchId": watchId, "atMs": atMs, "status": status],
            forKey: shareResultKey
        )
    }

    static func lastShareResult() -> (watchId: String, atMs: Int, status: Int)? {
        guard let stored = UserDefaults.standard.dictionary(forKey: shareResultKey),
              let watchId = stored["watchId"] as? String,
              let atMs = stored["atMs"] as? Int,
              let status = stored["status"] as? Int else { return nil }
        return (watchId, atMs, status)
    }

    static func clearShareResult() {
        UserDefaults.standard.removeObject(forKey: shareResultKey)
    }
}
