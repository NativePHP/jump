import Foundation
import CoreLocation

// MARK: - Geolocation Bridge Functions

enum GeolocationFunctions {

    // MARK: - Geolocation.GetCurrentPosition

    /// Get the current GPS location
    /// Parameters:
    ///   - fineAccuracy: boolean (optional) - Whether to use high accuracy mode
    ///   - id: string (optional) - Unique identifier for this request
    ///   - event: string (optional) - Custom event class name to dispatch
    /// Returns: empty map (results come via LocationReceived event or custom event)
    class GetCurrentPosition: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fineAccuracy = parameters["fineAccuracy"] as? Bool ?? false
            let id = parameters["id"] as? String
            let eventClass = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Geolocation\\LocationReceived"

            print("[Geolocation] GetCurrentPosition called with fineAccuracy: \(fineAccuracy), id: \(id ?? "none"), event: \(eventClass)")

            DispatchQueue.main.async {
                GeolocationManager.shared.getCurrentLocation(fineAccuracy: fineAccuracy, id: id, eventClass: eventClass)
            }

            return [:]
        }
    }

    // MARK: - Geolocation.WatchPosition

    /// Start streaming continuous location updates (watchPosition)
    /// Parameters:
    ///   - id: string (required) - Watch identifier; every update event carries it
    ///   - event: string (optional) - Custom event class name to dispatch per update
    ///   - fineAccuracy: boolean (optional) - High accuracy (GPS) vs balanced
    ///   - minDistance: double (optional) - Meters moved before another update
    ///   - interval: int (optional) - Ignored on iOS (CoreLocation is event-driven)
    /// Returns: empty map (updates stream via LocationUpdated events)
    class WatchPosition: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String, !id.isEmpty else {
                return BridgeResponse.error(
                    code: "geolocation.missing_id",
                    message: "WatchPosition requires an id"
                )
            }

            let fineAccuracy = parameters["fineAccuracy"] as? Bool ?? false
            let minDistance = (parameters["minDistance"] as? Double)
                ?? (parameters["minDistance"] as? Int).map(Double.init) ?? 0
            let eventClass = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Geolocation\\LocationUpdated"

            print("[Geolocation] WatchPosition called - id: \(id), fineAccuracy: \(fineAccuracy), minDistance: \(minDistance)")

            DispatchQueue.main.async {
                GeolocationManager.shared.startWatch(
                    id: id, eventClass: eventClass,
                    fineAccuracy: fineAccuracy, minDistance: minDistance
                )
            }

            return [:]
        }
    }

    // MARK: - Geolocation.ClearWatch

    /// Stop a location watch started with WatchPosition
    /// Parameters:
    ///   - id: string (required) - The watch identifier to stop
    class ClearWatch: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String ?? ""

            print("[Geolocation] ClearWatch called - id: \(id)")

            DispatchQueue.main.async {
                GeolocationManager.shared.stopWatch(id: id)
            }

            return [:]
        }
    }

    // MARK: - Geolocation.CheckPermissions

    /// Check location permissions status
    /// Parameters:
    ///   - id: string (optional) - Unique identifier for this request
    ///   - event: string (optional) - Custom event class name to dispatch
    /// Returns: empty map (results come via PermissionStatusReceived event or custom event)
    class CheckPermissions: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String
            let eventClass = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived"

            print("[Geolocation] CheckPermissions called with id: \(id ?? "none"), event: \(eventClass)")

            DispatchQueue.main.async {
                GeolocationManager.shared.checkLocationPermissions(id: id, eventClass: eventClass)
            }

            return [:]
        }
    }

    // MARK: - Geolocation.RequestPermissions

    /// Request location permissions from user
    /// Parameters:
    ///   - id: string (optional) - Unique identifier for this request
    ///   - event: string (optional) - Custom event class name to dispatch
    /// Returns: empty map (results come via PermissionRequestResult event or custom event)
    class RequestPermissions: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String
            let eventClass = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"

            print("[Geolocation] RequestPermissions called with id: \(id ?? "none"), event: \(eventClass)")

            DispatchQueue.main.async {
                GeolocationManager.shared.requestLocationPermissions(id: id, eventClass: eventClass)
            }

            return [:]
        }
    }
}

// MARK: - Geolocation Manager

final class GeolocationManager: NSObject, CLLocationManagerDelegate {
    static let shared = GeolocationManager()

    private let locationManager = CLLocationManager()
    private var isRequestingLocation = false
    private var fineAccuracy = false
    private var timeoutWorkItem: DispatchWorkItem?

    // Store id and event class for async callbacks
    private var pendingLocationId: String?
    private var pendingLocationEventClass: String = "Native\\Mobile\\Events\\Geolocation\\LocationReceived"
    private var pendingPermissionRequestId: String?
    private var pendingPermissionEventClass: String = "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"

    // Active location watches (streaming), keyed by watch id. Each watch owns
    // its own CLLocationManager so streams never interfere with the one-shot
    // getCurrentLocation flow or with each other.
    private var watches: [String: LocationWatchSession] = [:]

    /// Watches requested before authorization was resolved — replayed from
    /// `locationManagerDidChangeAuthorization` once the user answers the
    /// prompt (started when granted, error-evented when denied).
    private var pendingWatches: [(id: String, eventClass: String, fineAccuracy: Bool, minDistance: Double)] = []

    /// A one-shot getCurrentLocation() queued behind the permission prompt —
    /// replayed from `locationManagerDidChangeAuthorization` once the user
    /// answers. Mirrors `pendingWatches` so getCurrentPosition() prompts like
    /// Android instead of failing on `.notDetermined`.
    private var pendingCurrentLocation = false

    /// Start (or replace) a continuous location watch.
    func startWatch(id: String, eventClass: String, fineAccuracy: Bool, minDistance: Double) {
        let authStatus = locationManager.authorizationStatus

        // Mirror Android's coordinator: an undetermined status prompts as
        // part of the watch flow instead of failing — otherwise
        // watchPosition() silently streams nothing until the app happens
        // to call requestPermissions() separately.
        if authStatus == .notDetermined {
            print("[Geolocation] Watch \(id) awaiting permission prompt")
            pendingWatches.append((id, eventClass, fineAccuracy, minDistance))
            locationManager.requestWhenInUseAuthorization()
            return
        }

        guard authStatus == .authorizedWhenInUse || authStatus == .authorizedAlways else {
            print("[Geolocation] Watch \(id) denied - authorization status: \(authStatus.rawValue)")
            LocationWatchSession.emitStream(eventClass, [
                "success": false,
                "timestamp": Int(Date().timeIntervalSince1970 * 1000),
                "error": "Location permission not granted",
                "id": id
            ])
            return
        }

        watches[id]?.stop()
        let session = LocationWatchSession(
            id: id, eventClass: eventClass,
            fineAccuracy: fineAccuracy, minDistance: minDistance
        )
        watches[id] = session
        session.start()
        print("[Geolocation] Watch \(id) started (\(watches.count) active)")
    }

    /// Stop and discard a watch. Unknown ids are a no-op.
    func stopWatch(id: String) {
        watches[id]?.stop()
        watches[id] = nil
        print("[Geolocation] Watch \(id) stopped (\(watches.count) active)")
    }

    override init() {
        super.init()
        locationManager.delegate = self
    }

    func checkLocationPermissions(id: String? = nil, eventClass: String = "Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived") {
        let status = locationManager.authorizationStatus
        let (generalStatus, fineStatus, coarseStatus) = getPermissionStatuses(status)

        var payload: [String: Any] = [
            "location": generalStatus,
            "fineLocation": fineStatus,
            "coarseLocation": coarseStatus
        ]

        if let id = id {
            payload["id"] = id
        }

        LaravelBridge.shared.send?(eventClass, payload)
    }

    func requestLocationPermissions(id: String? = nil, eventClass: String = "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult") {
        // Store for use in delegate callback
        self.pendingPermissionRequestId = id
        self.pendingPermissionEventClass = eventClass
        locationManager.requestWhenInUseAuthorization()
    }

    func getCurrentLocation(fineAccuracy: Bool, id: String? = nil, eventClass: String = "Native\\Mobile\\Events\\Geolocation\\LocationReceived") {
        self.pendingLocationId = id
        self.pendingLocationEventClass = eventClass
        print("[Geolocation] getCurrentLocation called with fineAccuracy: \(fineAccuracy)")
        self.fineAccuracy = fineAccuracy

        let authStatus = locationManager.authorizationStatus
        print("[Geolocation] Current authorization status: \(authStatus.rawValue)")

        // Match Android's coordinator: an undetermined status prompts as part
        // of the getCurrentPosition() flow instead of failing — otherwise a
        // one-shot request silently errors until the app happens to call
        // requestPermissions() separately. Replayed from
        // locationManagerDidChangeAuthorization once the user answers.
        if authStatus == .notDetermined {
            print("[Geolocation] getCurrentLocation awaiting permission prompt")
            pendingCurrentLocation = true
            locationManager.requestWhenInUseAuthorization()
            return
        }

        guard authStatus == .authorizedWhenInUse || authStatus == .authorizedAlways else {
            print("[Geolocation] Authorization failed - status: \(authStatus)")
            sendLocationError("Location permission not granted")
            return
        }

        print("[Geolocation] Setting isRequestingLocation = true")
        isRequestingLocation = true

        // For simulator, use less demanding accuracy
        let accuracy = fineAccuracy ? kCLLocationAccuracyHundredMeters : kCLLocationAccuracyKilometer
        locationManager.desiredAccuracy = accuracy
        print("[Geolocation] Setting desired accuracy to: \(accuracy)")

        // Check cached location first - if very fresh, use immediately
        if let lastLocation = locationManager.location {
            let locationAge = Date().timeIntervalSince(lastLocation.timestamp)
            let ageMinutes = locationAge / 60.0
            print("[Geolocation] Found cached location (age: \(locationAge) seconds / \(ageMinutes) minutes)")

            if locationAge < 30 { // If less than 30 seconds old, use it
                print("[Geolocation] Using cached location (very fresh)")
                isRequestingLocation = false

                sendLocationEvent([
                    "success": true,
                    "latitude": lastLocation.coordinate.latitude,
                    "longitude": lastLocation.coordinate.longitude,
                    "accuracy": lastLocation.horizontalAccuracy,
                    "timestamp": Int(Date().timeIntervalSince1970 * 1000),
                    "provider": "core_location_cached",
                    "error": false
                ])
                return
            } else {
                print("[Geolocation] Cached location is stale, requesting fresh location")
            }
        } else {
            print("[Geolocation] No cached location available, requesting fresh location")
        }

        // Request fresh location for stale/missing cache
        print("[Geolocation] Requesting fresh location with requestLocation()")
        locationManager.requestLocation()

        // Set shorter timeout since we have fallback
        print("[Geolocation] Setting up 5-second timeout")
        timeoutWorkItem = DispatchWorkItem { [weak self] in
            print("[Geolocation] Fresh location request timed out, using cached fallback")
            self?.handleLocationTimeout()
        }

        if let workItem = timeoutWorkItem {
            DispatchQueue.main.asyncAfter(deadline: .now() + 5.0, execute: workItem)
        }
    }

    private func handleLocationTimeout() {
        // Try to use cached location as fallback
        if let lastLocation = locationManager.location {
            let locationAge = Date().timeIntervalSince(lastLocation.timestamp)
            print("[Geolocation] Using cached location as fallback (age: \(locationAge) seconds)")

            stopLocationRequest()

            sendLocationEvent([
                "success": true,
                "latitude": lastLocation.coordinate.latitude,
                "longitude": lastLocation.coordinate.longitude,
                "accuracy": lastLocation.horizontalAccuracy,
                "timestamp": Int(Date().timeIntervalSince1970 * 1000),
                "provider": "core_location_cached",
                "error": false
            ])
        } else {
            print("[Geolocation] No cached location available, sending timeout error")
            stopLocationRequest()
            sendLocationError("Location request timed out and no cached location available")
        }
    }

    private func stopLocationRequest() {
        print("[Geolocation] Stopping location request")
        isRequestingLocation = false
        locationManager.stopUpdatingLocation()
        timeoutWorkItem?.cancel()
        timeoutWorkItem = nil
    }

    private func sendLocationEvent(_ payload: [String: Any]) {
        var mutablePayload = payload
        if let id = pendingLocationId {
            mutablePayload["id"] = id
        }
        LaravelBridge.shared.send?(pendingLocationEventClass, mutablePayload)
    }

    private func sendLocationError(_ message: String) {
        sendLocationEvent([
            "success": false,
            "latitude": nil,
            "longitude": nil,
            "accuracy": nil,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "provider": nil,
            "error": message
        ])
        print("[Geolocation] Error event sent: \(message)")
    }

    private func getPermissionStatuses(_ status: CLAuthorizationStatus) -> (String, String, String) {
        switch status {
        case .notDetermined:
            // Never asked yet — distinct from denied: a permission request is
            // still possible (and will show the system dialog).
            return ("not_determined", "not_determined", "not_determined")
        case .denied, .restricted:
            // On iOS a denial IS permanent (re-prompting is impossible; the
            // user must enable it in Settings).
            return ("permanently_denied", "permanently_denied", "permanently_denied")
        case .authorizedWhenInUse, .authorizedAlways:
            // iOS doesn't distinguish between fine and coarse like Android
            // Both are granted when any location permission is granted
            return ("granted", "granted", "granted")
        @unknown default:
            return ("not_determined", "not_determined", "not_determined")
        }
    }

    // MARK: - CLLocationManagerDelegate

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        // .notDetermined fires BEFORE the user answers — once when the delegate
        // is set at init, and again when requestWhenInUseAuthorization()
        // presents the dialog. Emitting PermissionRequestResult then consumes
        // the app's one-shot callback with a premature "not_determined" (apps
        // render it as a denial while the dialog is still on screen) and the
        // real answer moments later finds no callback. Only report once the
        // status is actually decided — same guard the replays below use.
        if manager.authorizationStatus != .notDetermined {
            let (generalStatus, fineStatus, coarseStatus) = getPermissionStatuses(manager.authorizationStatus)

            var payload: [String: Any] = [
                "location": generalStatus,
                "fineLocation": fineStatus,
                "coarseLocation": coarseStatus
            ]

            if let id = pendingPermissionRequestId {
                payload["id"] = id
                pendingPermissionRequestId = nil // one result per request
            }

            LaravelBridge.shared.send?(pendingPermissionEventClass, payload)
        }

        // Replay watches that were waiting on the permission prompt. Once
        // the status is resolved, re-entering startWatch either starts the
        // stream (granted) or emits the denial error event. Guard on
        // .notDetermined so the initial delegate callback at manager init
        // (before any answer) doesn't consume the queue.
        if manager.authorizationStatus != .notDetermined, !pendingWatches.isEmpty {
            let pending = pendingWatches
            pendingWatches = []
            for watch in pending {
                startWatch(
                    id: watch.id, eventClass: watch.eventClass,
                    fineAccuracy: watch.fineAccuracy, minDistance: watch.minDistance
                )
            }
        }

        // Replay a one-shot position queued behind the prompt: re-enter
        // getCurrentLocation (which now sees the resolved status) to fetch on
        // grant or emit the denial error. Guarded on .notDetermined so the
        // initial delegate callback at manager init doesn't consume it.
        if manager.authorizationStatus != .notDetermined, pendingCurrentLocation {
            pendingCurrentLocation = false
            getCurrentLocation(
                fineAccuracy: fineAccuracy,
                id: pendingLocationId,
                eventClass: pendingLocationEventClass
            )
        }
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        print("[Geolocation] didUpdateLocations called - isRequestingLocation: \(isRequestingLocation)")
        print("[Geolocation] Received \(locations.count) locations")

        guard isRequestingLocation, let location = locations.last else {
            print("[Geolocation] Ignoring location update - not requesting or no location")
            return
        }

        print("[Geolocation] Got location: \(location.coordinate.latitude), \(location.coordinate.longitude)")

        stopLocationRequest()

        sendLocationEvent([
            "success": true,
            "latitude": location.coordinate.latitude,
            "longitude": location.coordinate.longitude,
            "accuracy": location.horizontalAccuracy,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "provider": "core_location",
            "error": false
        ])
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        print("[Geolocation] didFailWithError called - isRequestingLocation: \(isRequestingLocation)")
        print("[Geolocation] Error: \(error.localizedDescription)")

        guard isRequestingLocation else {
            print("[Geolocation] Ignoring error - not requesting location")
            return
        }

        stopLocationRequest()
        sendLocationError(error.localizedDescription)
    }
}

// MARK: - Location Watch Session

/// One continuous location stream (Geolocation.WatchPosition). Owns a private
/// CLLocationManager and forwards every fix as a LocationUpdated event tagged
/// with the watch id, until stopped (Geolocation.ClearWatch or PHP-side
/// component unmount).
final class LocationWatchSession: NSObject, CLLocationManagerDelegate {
    private let manager = CLLocationManager()
    private let id: String
    private let eventClass: String

    init(id: String, eventClass: String, fineAccuracy: Bool, minDistance: Double) {
        self.id = id
        self.eventClass = eventClass
        super.init()

        manager.desiredAccuracy = fineAccuracy ? kCLLocationAccuracyBest : kCLLocationAccuracyHundredMeters
        manager.distanceFilter = minDistance > 0 ? minDistance : kCLDistanceFilterNone
        manager.delegate = self
    }

    func start() {
        manager.startUpdatingLocation()
    }

    func stop() {
        manager.stopUpdatingLocation()
        manager.delegate = nil
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let location = locations.last else { return }

        var payload: [String: Any] = [
            "success": true,
            "latitude": location.coordinate.latitude,
            "longitude": location.coordinate.longitude,
            "accuracy": location.horizontalAccuracy,
            "timestamp": Int(location.timestamp.timeIntervalSince1970 * 1000),
            "provider": "core_location",
            "error": false,
            "id": id
        ]
        if location.speed >= 0 { payload["speed"] = location.speed }
        if location.course >= 0 { payload["heading"] = location.course }

        LocationWatchSession.emitStream(eventClass, payload)
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        // kCLErrorLocationUnknown is transient - CoreLocation keeps trying.
        if (error as NSError).code == CLError.locationUnknown.rawValue {
            return
        }

        print("[Geolocation] Watch \(id) error: \(error.localizedDescription)")
        LocationWatchSession.emitStream(eventClass, [
            "success": false,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
            "error": error.localizedDescription,
            "id": id
        ])
    }

    /// Deliver a STREAMING event straight to the element event queue, skipping
    /// LaravelBridge's webview-JS + HTTP POST legs. One-shot results (a photo,
    /// a permission answer) can afford that round-trip; a location fix every
    /// couple of seconds cannot — each POST boots a full Laravel request, and
    /// the flood destabilizes the event channel.
    static func emitStream(_ eventClass: String, _ payload: [String: Any]) {
        guard let data = try? JSONSerialization.data(withJSONObject: payload),
              let json = String(data: data, encoding: .utf8) else { return }
        NativeElementBridge.sendNativeEvent(eventName: eventClass, payloadJson: json)
    }
}