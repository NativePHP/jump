package com.nativephp.geolocation

import android.Manifest
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.google.android.gms.location.*
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

/**
 * GeolocationCoordinator handles location permission requests and location retrieval.
 * It's a headless fragment that manages:
 * - Location permission requests (fine/coarse)
 * - Location retrieval via FusedLocationProviderClient
 * - Event dispatching back to PHP/Livewire
 */
class GeolocationCoordinator : Fragment() {

    private var pendingLocationPermissionId: String? = null
    private var pendingLocationPermissionEvent: String? = null
    private var activeLocationCallback: LocationCallback? = null
    private var activeLocationClient: FusedLocationProviderClient? = null
    private var locationRequestCounter: Int = 0

    // Continuous location watches (WatchPosition), keyed by watch id.
    // Deliberately separate from the one-shot fields above so a watch and a
    // getCurrentPosition request never tear each other down.
    private val watchCallbacks = mutableMapOf<String, LocationCallback>()
    private var watchClient: FusedLocationProviderClient? = null

    /**
     * Background watch waiting on the permission prompt — replayed from
     * the permission launcher callback (the Android mirror of iOS's
     * BackgroundLocationRecorder.pendingConfig).
     */
    private var pendingBgWatchConfig: LocationWatchService.WatchConfig? = null

    /**
     * Foreground watch (WatchPosition) waiting on the permission prompt —
     * same replay pattern as the background config above and as iOS's
     * GeolocationManager.pendingWatches.
     */
    private data class PendingForegroundWatch(
        val fineAccuracy: Boolean,
        val intervalMs: Long,
        val minDistanceMeters: Float,
        val id: String,
        val eventClass: String,
    )

    private var pendingForegroundWatch: PendingForegroundWatch? = null

    /**
     * One-shot getCurrentPosition() waiting on the permission prompt —
     * replayed from the permission launcher callback so getCurrentPosition()
     * prompts inline instead of failing, matching iOS's
     * GeolocationManager.pendingCurrentLocation.
     */
    private data class PendingLocationRequest(
        val fineAccuracy: Boolean,
        val id: String?,
        val eventClass: String,
    )

    private var pendingLocationRequest: PendingLocationRequest? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Log.d(TAG, "📍 GeolocationCoordinator created")
    }

    override fun onDestroy() {
        super.onDestroy()
        // Stop active location requests to prevent leaks
        stopActiveLocationRequest()
        stopAllWatches()
        Log.d(TAG, "🧹 GeolocationCoordinator destroyed and resources cleaned up")
    }

    // Location permission launcher
    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { permissions ->
            Log.d(TAG, "🔒 Location permission callback triggered")

            val fineLocationGranted = permissions[Manifest.permission.ACCESS_FINE_LOCATION] ?: false
            val coarseLocationGranted = permissions[Manifest.permission.ACCESS_COARSE_LOCATION] ?: false

            val locationPermissionStatus = when {
                fineLocationGranted -> "granted"
                coarseLocationGranted -> "granted"
                else -> "denied"
            }

            Log.d(TAG, "📋 Permission results - Fine: $fineLocationGranted, Coarse: $coarseLocationGranted")

            val payload = JSONObject().apply {
                put("location", locationPermissionStatus)
                put("coarseLocation", if (coarseLocationGranted) "granted" else "denied")
                put("fineLocation", if (fineLocationGranted) "granted" else "denied")
                if (pendingLocationPermissionId != null) {
                    put("id", pendingLocationPermissionId)
                }
            }

            val eventClass = pendingLocationPermissionEvent ?: "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"
            dispatch(eventClass, payload.toString())

            // Clean up pending state
            pendingLocationPermissionId = null
            pendingLocationPermissionEvent = null

            // A background watch was waiting on this prompt: start it on
            // grant, error-event it on denial.
            pendingBgWatchConfig?.let { cfg ->
                pendingBgWatchConfig = null
                if (fineLocationGranted || coarseLocationGranted) {
                    Log.d(TAG, "✅ Permission granted — starting deferred background watch ${cfg.id}")
                    LocationWatchService.start(requireContext(), cfg)
                } else {
                    Log.w(TAG, "❌ Permission denied — background watch ${cfg.id} not started")
                    dispatchStream(cfg.eventClass, watchErrorPayload(cfg.id, "Location permissions not granted").toString())
                }
            }

            // Same replay for a deferred foreground watch.
            pendingForegroundWatch?.let { w ->
                pendingForegroundWatch = null
                if (fineLocationGranted || coarseLocationGranted) {
                    Log.d(TAG, "✅ Permission granted — starting deferred watch ${w.id}")
                    startWatch(w.fineAccuracy, w.intervalMs, w.minDistanceMeters, w.id, w.eventClass)
                } else {
                    Log.w(TAG, "❌ Permission denied — watch ${w.id} not started")
                    dispatchStream(w.eventClass, watchErrorPayload(w.id, "Location permissions not granted").toString())
                }
            }

            // Same replay for a deferred one-shot getCurrentPosition().
            pendingLocationRequest?.let { req ->
                pendingLocationRequest = null
                if (fineLocationGranted || coarseLocationGranted) {
                    Log.d(TAG, "✅ Permission granted — running deferred location request")
                    launchLocationRequest(req.fineAccuracy, req.id, req.eventClass)
                } else {
                    Log.w(TAG, "❌ Permission denied — location request not run")
                    val payload = JSONObject().apply {
                        put("success", false)
                        put("latitude", JSONObject.NULL)
                        put("longitude", JSONObject.NULL)
                        put("accuracy", JSONObject.NULL)
                        if (req.id != null) put("id", req.id)
                        put("timestamp", System.currentTimeMillis())
                        put("provider", JSONObject.NULL)
                        put("error", "Location permissions not granted")
                    }
                    dispatch(req.eventClass, payload.toString())
                }
            }
        }

    /**
     * Prompt-then-start for a background watch — the Android mirror of
     * iOS's not-determined flow. Already granted → start immediately;
     * otherwise show the system permission dialog and start on grant
     * (permanently denied auto-resolves instantly to the error path).
     */
    fun startBackgroundWatchWithPermission(config: LocationWatchService.WatchConfig) {
        val context = requireContext()
        val granted = ContextCompat.checkSelfPermission(
            context, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(
                context, Manifest.permission.ACCESS_COARSE_LOCATION
            ) == PackageManager.PERMISSION_GRANTED

        if (granted) {
            LocationWatchService.start(context, config)
            return
        }

        Log.d(TAG, "🔒 Background watch ${config.id} awaiting permission prompt")
        pendingBgWatchConfig = config
        locationPermissionLauncher.launch(
            arrayOf(
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION,
            )
        )
    }

    /**
     * Request current location with aggressive updates.
     * First tries to get last known location as a quick response, then requests fresh location.
     */
    fun launchLocationRequest(fineAccuracy: Boolean, id: String? = null, eventClass: String = "Native\\Mobile\\Events\\Geolocation\\LocationReceived") {
        locationRequestCounter++
        val requestId = locationRequestCounter
        Log.d(TAG, "📍 Starting location request #$requestId (fineAccuracy: $fineAccuracy, id: ${id ?: "none"}, event: $eventClass)")

        // Check if there's already an active location request
        if (activeLocationCallback != null || activeLocationClient != null) {
            Log.w(TAG, "⚠️ Cleaning up previous location request first")
            stopActiveLocationRequest()
        }

        val context = requireContext()

        // Check if location permissions are granted
        val fineLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        val coarseLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_COARSE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        if (!fineLocationGranted && !coarseLocationGranted) {
            // Prompt as part of the getCurrentPosition() flow instead of
            // failing — replayed from the permission launcher callback on
            // grant, error-evented on denial. Mirrors the watch flow and
            // iOS's not-determined handling.
            Log.d(TAG, "🔒 getCurrentPosition awaiting permission prompt")
            pendingLocationRequest = PendingLocationRequest(fineAccuracy, id, eventClass)
            locationPermissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION,
                )
            )
            return
        }

        // Create location client
        val fusedLocationClient = LocationServices.getFusedLocationProviderClient(requireActivity())
        activeLocationClient = fusedLocationClient

        // Use BALANCED priority which uses both network and GPS for faster results
        val priority = if (fineAccuracy && fineLocationGranted) {
            Priority.PRIORITY_HIGH_ACCURACY
        } else {
            Priority.PRIORITY_BALANCED_POWER_ACCURACY
        }

        Log.d(TAG, "🚀 Requesting location (priority: $priority)")

        try {
            // First, try to get the last known location as a quick fallback
            fusedLocationClient.lastLocation.addOnSuccessListener { lastLocation ->
                if (lastLocation != null) {
                    // Check if last location is recent (within 2 minutes)
                    val locationAge = System.currentTimeMillis() - lastLocation.time
                    if (locationAge < 120000) { // 2 minutes
                        Log.d(TAG, "✅ Using recent cached location (age: ${locationAge/1000}s)")
                        val payload = JSONObject().apply {
                            put("success", true)
                            put("latitude", lastLocation.latitude)
                            put("longitude", lastLocation.longitude)
                            put("accuracy", lastLocation.accuracy)
                            if (id != null) put("id", id)
                            put("timestamp", System.currentTimeMillis())
                            put("provider", lastLocation.provider ?: "cached")
                            put("error", false)
                        }
                        dispatch(eventClass, payload.toString())
                        return@addOnSuccessListener
                    } else {
                        Log.d(TAG, "ℹ️ Cached location too old (age: ${locationAge/1000}s), requesting fresh")
                    }
                } else {
                    Log.d(TAG, "ℹ️ No cached location available, requesting fresh")
                }

                // Request fresh location with aggressive updates
                requestFreshLocation(fusedLocationClient, priority, id, eventClass)
            }.addOnFailureListener { exception ->
                Log.w(TAG, "⚠️ Failed to get last location: ${exception.message}, requesting fresh")
                // Fallback to requesting fresh location
                requestFreshLocation(fusedLocationClient, priority, id, eventClass)
            }
        } catch (e: SecurityException) {
            Log.e(TAG, "❌ Security exception: ${e.message}")
            val payload = JSONObject().apply {
                put("success", false)
                put("latitude", JSONObject.NULL)
                put("longitude", JSONObject.NULL)
                put("accuracy", JSONObject.NULL)
                if (id != null) put("id", id)
                put("timestamp", System.currentTimeMillis())
                put("provider", JSONObject.NULL)
                put("error", "Security exception: ${e.message}")
            }
            dispatch(eventClass, payload.toString())
        }
    }

    /**
     * Request fresh location with aggressive updates
     */
    private fun requestFreshLocation(
        fusedLocationClient: FusedLocationProviderClient,
        priority: Int,
        id: String?,
        eventClass: String
    ) {
        try {
            // Create location request - use 2 second interval for better battery balance
            val locationRequest = LocationRequest.Builder(priority, 2000L)
                .setMinUpdateIntervalMillis(1000L)
                .setWaitForAccurateLocation(false) // Don't wait for perfect accuracy
                .build()

            // Create callback that stops updates after first result
            val locationCallback = object : LocationCallback() {
                override fun onLocationResult(locationResult: LocationResult) {
                    super.onLocationResult(locationResult)

                    val location = locationResult.lastLocation
                    if (location != null) {
                        Log.d(TAG, "✅ Fresh location received: lat=${location.latitude}, lng=${location.longitude}, accuracy=${location.accuracy}m")

                        val payload = JSONObject().apply {
                            put("success", true)
                            put("latitude", location.latitude)
                            put("longitude", location.longitude)
                            put("accuracy", location.accuracy)
                            if (id != null) put("id", id)
                            put("timestamp", System.currentTimeMillis())
                            put("provider", location.provider ?: "fused")
                            put("error", false)
                        }
                        dispatch(eventClass, payload.toString())
                        stopActiveLocationRequest()
                    } else {
                        Log.e(TAG, "❌ Location result was null")
                        val payload = JSONObject().apply {
                            put("success", false)
                            put("latitude", JSONObject.NULL)
                            put("longitude", JSONObject.NULL)
                            put("accuracy", JSONObject.NULL)
                            if (id != null) put("id", id)
                            put("timestamp", System.currentTimeMillis())
                            put("provider", JSONObject.NULL)
                            put("error", "Location result was null")
                        }
                        dispatch(eventClass, payload.toString())
                        stopActiveLocationRequest()
                    }
                }
            }

            activeLocationCallback = locationCallback

            // Start location updates
            fusedLocationClient.requestLocationUpdates(
                locationRequest,
                locationCallback,
                Looper.getMainLooper()
            ).addOnSuccessListener {
                Log.d(TAG, "✅ Location updates started")
            }.addOnFailureListener { exception ->
                Log.e(TAG, "❌ Failed to start location updates: ${exception.message}")
                val payload = JSONObject().apply {
                    put("success", false)
                    put("latitude", JSONObject.NULL)
                    put("longitude", JSONObject.NULL)
                    put("accuracy", JSONObject.NULL)
                    if (id != null) put("id", id)
                    put("timestamp", System.currentTimeMillis())
                    put("provider", JSONObject.NULL)
                    put("error", "Failed to start location updates: ${exception.message}")
                }
                dispatch(eventClass, payload.toString())
                stopActiveLocationRequest()
            }

            // Set a timeout - 30 seconds for fresh location (GPS can take time)
            Handler(Looper.getMainLooper()).postDelayed({
                if (activeLocationCallback === locationCallback) {
                    Log.w(TAG, "⏰ Location request timed out after 30s")
                    stopActiveLocationRequest()
                    val payload = JSONObject().apply {
                        put("success", false)
                        put("latitude", JSONObject.NULL)
                        put("longitude", JSONObject.NULL)
                        put("accuracy", JSONObject.NULL)
                        if (id != null) put("id", id)
                        put("timestamp", System.currentTimeMillis())
                        put("provider", JSONObject.NULL)
                        put("error", "Location request timed out")
                    }
                    dispatch(eventClass, payload.toString())
                }
            }, 30000L) // 30 second timeout for fresh location
        } catch (e: SecurityException) {
            Log.e(TAG, "❌ Security exception in requestFreshLocation: ${e.message}")
            val payload = JSONObject().apply {
                put("success", false)
                put("latitude", JSONObject.NULL)
                put("longitude", JSONObject.NULL)
                put("accuracy", JSONObject.NULL)
                if (id != null) put("id", id)
                put("timestamp", System.currentTimeMillis())
                put("provider", JSONObject.NULL)
                put("error", "Security exception: ${e.message}")
            }
            dispatch(eventClass, payload.toString())
        }
    }

    /**
     * Start (or replace) a continuous location watch. Every fix dispatches
     * the event (default LocationUpdated) tagged with the watch id, until
     * stopWatch(id) — or the coordinator is destroyed.
     */
    fun startWatch(
        fineAccuracy: Boolean,
        intervalMs: Long,
        minDistanceMeters: Float,
        id: String,
        eventClass: String,
    ) {
        Log.d(TAG, "📡 Starting watch $id (fineAccuracy: $fineAccuracy, interval: ${intervalMs}ms, minDistance: ${minDistanceMeters}m)")

        val context = requireContext()
        val fineLocationGranted = ContextCompat.checkSelfPermission(
            context, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        val coarseLocationGranted = ContextCompat.checkSelfPermission(
            context, Manifest.permission.ACCESS_COARSE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        if (!fineLocationGranted && !coarseLocationGranted) {
            // Prompt as part of the watch flow instead of failing —
            // otherwise watchPosition() silently streams nothing until the
            // app happens to call requestPermissions() separately. A
            // permanently-denied state auto-resolves the dialog instantly
            // into the error path below via the launcher callback.
            Log.d(TAG, "🔒 Watch $id awaiting permission prompt")
            pendingForegroundWatch = PendingForegroundWatch(fineAccuracy, intervalMs, minDistanceMeters, id, eventClass)
            locationPermissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION,
                )
            )
            return
        }

        // Replacing an existing watch with the same id
        stopWatch(id)

        val client = watchClient
            ?: LocationServices.getFusedLocationProviderClient(requireActivity()).also { watchClient = it }

        val priority = if (fineAccuracy && fineLocationGranted) {
            Priority.PRIORITY_HIGH_ACCURACY
        } else {
            Priority.PRIORITY_BALANCED_POWER_ACCURACY
        }

        val interval = intervalMs.coerceAtLeast(1000L)
        val request = LocationRequest.Builder(priority, interval)
            .setMinUpdateIntervalMillis(interval / 2)
            .setMinUpdateDistanceMeters(minDistanceMeters)
            .setWaitForAccurateLocation(false)
            .build()

        val callback = object : LocationCallback() {
            override fun onLocationResult(locationResult: LocationResult) {
                val location = locationResult.lastLocation ?: return
                val payload = JSONObject().apply {
                    put("success", true)
                    put("latitude", location.latitude)
                    put("longitude", location.longitude)
                    put("accuracy", location.accuracy)
                    if (location.hasSpeed()) put("speed", location.speed) else put("speed", JSONObject.NULL)
                    if (location.hasBearing()) put("heading", location.bearing) else put("heading", JSONObject.NULL)
                    put("timestamp", location.time)
                    put("provider", location.provider ?: "fused")
                    put("error", false)
                    put("id", id)
                }
                dispatchStream(eventClass, payload.toString())
            }
        }

        try {
            client.requestLocationUpdates(request, callback, Looper.getMainLooper())
                .addOnSuccessListener {
                    Log.d(TAG, "✅ Watch $id streaming (${watchCallbacks.size} active)")
                }
                .addOnFailureListener { exception ->
                    Log.e(TAG, "❌ Watch $id failed to start: ${exception.message}")
                    watchCallbacks.remove(id)
                    dispatchStream(eventClass, watchErrorPayload(id, "Failed to start location updates: ${exception.message}").toString())
                }
            watchCallbacks[id] = callback
        } catch (e: SecurityException) {
            Log.e(TAG, "❌ Security exception starting watch $id: ${e.message}")
            dispatchStream(eventClass, watchErrorPayload(id, "Security exception: ${e.message}").toString())
        }
    }

    /**
     * Deliver a STREAMING event straight to the element event queue, skipping
     * NativeActionCoordinator's webview-JS + HTTP POST legs. One-shot results
     * (a photo, a permission answer) can afford that round-trip; a location
     * fix every couple of seconds cannot — each POST boots a full Laravel
     * request, and the flood destabilizes the event channel.
     */
    private fun dispatchStream(event: String, payloadJson: String) {
        Log.d(TAG, "📢 Streaming event: $event")
        try {
            NativeElementBridge.sendNativeEvent(event, payloadJson)
        } catch (e: Exception) {
            Log.d(TAG, "Stream event dropped (no active region): ${e.message}")
        }
    }

    /** Stop a watch. Unknown ids are a no-op. */
    fun stopWatch(id: String) {
        val callback = watchCallbacks.remove(id) ?: return
        watchClient?.removeLocationUpdates(callback)
        Log.d(TAG, "🛑 Watch $id stopped (${watchCallbacks.size} active)")
    }

    private fun stopAllWatches() {
        watchCallbacks.keys.toList().forEach { stopWatch(it) }
        watchClient = null
    }

    private fun watchErrorPayload(id: String, error: String): JSONObject = JSONObject().apply {
        put("success", false)
        put("latitude", JSONObject.NULL)
        put("longitude", JSONObject.NULL)
        put("accuracy", JSONObject.NULL)
        put("timestamp", System.currentTimeMillis())
        put("provider", JSONObject.NULL)
        put("error", error)
        put("id", id)
    }

    private fun stopActiveLocationRequest() {
        try {
            if (activeLocationCallback != null && activeLocationClient != null) {
                activeLocationClient?.removeLocationUpdates(activeLocationCallback!!)
                    ?.addOnSuccessListener {
                        Log.d(TAG, "✅ Location updates stopped")
                    }
                    ?.addOnFailureListener { exception ->
                        Log.e(TAG, "❌ Failed to stop location updates: ${exception.message}")
                    }
                activeLocationCallback = null
                activeLocationClient = null
            }
        } catch (e: Exception) {
            Log.e(TAG, "❌ Error stopping location request", e)
            // Force clear even if there was an error
            activeLocationCallback = null
            activeLocationClient = null
        }
    }

    /**
     * Check location permissions status
     */
    fun launchLocationPermissionCheck(id: String? = null, eventClass: String = "Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived") {
        Log.d(TAG, "🔒 launchLocationPermissionCheck called with id: ${id ?: "none"}, event: $eventClass")

        val context = requireContext()
        val fineLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        val coarseLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_COARSE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        val locationPermissionStatus = when {
            fineLocationGranted -> "granted"
            coarseLocationGranted -> "granted"
            else -> "denied"
        }

        Log.d(TAG, "📋 Permission status - Fine: $fineLocationGranted, Coarse: $coarseLocationGranted")

        val payload = JSONObject().apply {
            put("location", locationPermissionStatus)
            put("coarseLocation", if (coarseLocationGranted) "granted" else "denied")
            put("fineLocation", if (fineLocationGranted) "granted" else "denied")
            if (id != null) {
                put("id", id)
            }
        }

        dispatch(eventClass, payload.toString())
    }

    /**
     * Request location permissions from user
     */
    fun launchLocationPermissionRequest(id: String? = null, eventClass: String = "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult") {
        Log.d(TAG, "🔒 launchLocationPermissionRequest called with id: ${id ?: "none"}, event: $eventClass")

        // Store for use in callback
        pendingLocationPermissionId = id
        pendingLocationPermissionEvent = eventClass

        val context = requireContext()
        val fineLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        val coarseLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_COARSE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        // If permissions are already granted, no need to request
        if (fineLocationGranted || coarseLocationGranted) {
            Log.d(TAG, "ℹ️ Location permissions already granted")
            val payload = JSONObject().apply {
                put("location", "granted")
                put("coarseLocation", if (coarseLocationGranted) "granted" else "denied")
                put("fineLocation", if (fineLocationGranted) "granted" else "denied")
                if (id != null) {
                    put("id", id)
                }
            }
            dispatch(eventClass, payload.toString())
            // Clean up
            pendingLocationPermissionId = null
            pendingLocationPermissionEvent = null
            return
        }

        // Check if we should show rationale or if permissions are permanently denied
        val shouldShowFineRationale = shouldShowRequestPermissionRationale(Manifest.permission.ACCESS_FINE_LOCATION)
        val shouldShowCoarseRationale = shouldShowRequestPermissionRationale(Manifest.permission.ACCESS_COARSE_LOCATION)

        if (!shouldShowFineRationale && !shouldShowCoarseRationale) {
            // Check if this is first time asking or permissions are permanently denied
            val activity = requireActivity()
            val prefs = activity.getSharedPreferences("geolocation_permissions", 0)
            val hasAskedBefore = prefs.getBoolean("has_asked_location_permissions", false)

            if (hasAskedBefore) {
                // Permissions are permanently denied. Report the status and let
                // the APP decide what to do — yanking the user out to system
                // Settings as a side effect of a permission request is
                // surprising; apps that want that can deep-link to Settings
                // themselves when they receive `permanently_denied`.
                Log.w(TAG, "⚠️ Location permissions permanently denied")
                val payload = JSONObject().apply {
                    put("location", "permanently_denied")
                    put("coarseLocation", "permanently_denied")
                    put("fineLocation", "permanently_denied")
                    put("error", "Location permissions were denied. Enable them in Settings > App Permissions > Location")
                    if (id != null) {
                        put("id", id)
                    }
                }
                dispatch(eventClass, payload.toString())
                // Clean up
                pendingLocationPermissionId = null
                pendingLocationPermissionEvent = null
                return
            } else {
                // First time asking, mark that we've asked
                prefs.edit().putBoolean("has_asked_location_permissions", true).apply()
            }
        }

        // Request permissions normally
        val permissions = arrayOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        )

        Log.d(TAG, "🚀 Requesting location permissions")
        locationPermissionLauncher.launch(permissions)
    }

    private fun dispatch(event: String, payloadJson: String) {
        Log.d(TAG, "📢 Dispatching event: $event")
        NativeActionCoordinator.dispatchEvent(requireActivity(), event, payloadJson)
    }

    companion object {
        private const val TAG = "GeolocationCoordinator"

        fun install(activity: FragmentActivity): GeolocationCoordinator =
            activity.supportFragmentManager.findFragmentByTag("GeolocationCoordinator") as? GeolocationCoordinator
                ?: GeolocationCoordinator().also {
                    activity.supportFragmentManager.beginTransaction()
                        .add(it, "GeolocationCoordinator")
                        .commitNow()
                }
    }
}