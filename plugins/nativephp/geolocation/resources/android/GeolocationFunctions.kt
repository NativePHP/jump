package com.nativephp.geolocation

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse

/**
 * Geolocation bridge functions for NativePHP
 * Namespace: "Geolocation.*"
 */
object GeolocationFunctions {

    /**
     * Get the current GPS location
     * Parameters:
     *   - fineAccuracy: boolean (optional) - Whether to use high accuracy mode
     *   - id: string (optional) - Unique identifier for this request
     *   - event: string (optional) - Custom event class name to dispatch
     * Returns: empty map (results come via LocationReceived event or custom event)
     */
    class GetCurrentPosition(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val fineAccuracy = parameters["fineAccuracy"] as? Boolean ?: false
            val id = parameters["id"] as? String
            val event = parameters["event"] as? String ?: "Native\\Mobile\\Events\\Geolocation\\LocationReceived"

            Log.d("Geolocation.GetCurrentPosition", "[Geolocation] Getting location with fineAccuracy: $fineAccuracy, id: ${id ?: "none"}, event: $event")

            Handler(Looper.getMainLooper()).post {
                try {
                    val coord = GeolocationCoordinator.install(activity)
                    coord.launchLocationRequest(fineAccuracy, id, event)
                    Log.d("Geolocation.GetCurrentPosition", "[Geolocation] Location request launched")
                } catch (e: Exception) {
                    Log.e("Geolocation.GetCurrentPosition", "[Geolocation] Error: ${e.message}", e)
                }
            }

            return emptyMap()
        }
    }

    /**
     * Start streaming continuous location updates (watchPosition)
     * Parameters:
     *   - id: string (required) - Watch identifier; every update event carries it
     *   - event: string (optional) - Custom event class name to dispatch per update
     *   - fineAccuracy: boolean (optional) - High accuracy (GPS) vs balanced
     *   - interval: number (optional) - Target ms between updates (default 5000)
     *   - minDistance: number (optional) - Meters moved before another update
     * Returns: empty map (updates stream via LocationUpdated events)
     */
    class WatchPosition(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            if (id.isNullOrEmpty()) {
                return BridgeResponse.error(
                    "geolocation.missing_id",
                    "WatchPosition requires an id"
                )
            }

            val fineAccuracy = parameters["fineAccuracy"] as? Boolean ?: false
            val intervalMs = (parameters["interval"] as? Number)?.toLong() ?: 5000L
            val minDistance = (parameters["minDistance"] as? Number)?.toFloat() ?: 0f
            val event = parameters["event"] as? String ?: "Native\\Mobile\\Events\\Geolocation\\LocationUpdated"

            Log.d("Geolocation.WatchPosition", "[Geolocation] Starting watch $id (fineAccuracy: $fineAccuracy, interval: $intervalMs, minDistance: $minDistance)")

            Handler(Looper.getMainLooper()).post {
                try {
                    val coord = GeolocationCoordinator.install(activity)
                    coord.startWatch(fineAccuracy, intervalMs, minDistance, id, event)
                } catch (e: Exception) {
                    Log.e("Geolocation.WatchPosition", "[Geolocation] Error: ${e.message}", e)
                }
            }

            return emptyMap()
        }
    }

    /**
     * Stop a location watch started with WatchPosition
     * Parameters:
     *   - id: string (required) - The watch identifier to stop
     */
    class ClearWatch(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String ?: ""

            Log.d("Geolocation.ClearWatch", "[Geolocation] Clearing watch $id")

            Handler(Looper.getMainLooper()).post {
                try {
                    val coord = GeolocationCoordinator.install(activity)
                    coord.stopWatch(id)
                } catch (e: Exception) {
                    Log.e("Geolocation.ClearWatch", "[Geolocation] Error: ${e.message}", e)
                }
            }

            return emptyMap()
        }
    }

    /**
     * Check location permissions status
     * Parameters:
     *   - id: string (optional) - Unique identifier for this request
     *   - event: string (optional) - Custom event class name to dispatch
     * Returns: empty map (results come via PermissionStatusReceived event or custom event)
     */
    class CheckPermissions(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            val event = parameters["event"] as? String ?: "Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived"

            Log.d("Geolocation.CheckPermissions", "[Geolocation] Checking location permissions with id: ${id ?: "none"}, event: $event")

            Handler(Looper.getMainLooper()).post {
                try {
                    val coord = GeolocationCoordinator.install(activity)
                    coord.launchLocationPermissionCheck(id, event)
                    Log.d("Geolocation.CheckPermissions", "[Geolocation] Permission check launched")
                } catch (e: Exception) {
                    Log.e("Geolocation.CheckPermissions", "[Geolocation] Error: ${e.message}", e)
                }
            }

            return emptyMap()
        }
    }

    /**
     * Request location permissions from user
     * Parameters:
     *   - id: string (optional) - Unique identifier for this request
     *   - event: string (optional) - Custom event class name to dispatch
     * Returns: empty map (results come via PermissionRequestResult event or custom event)
     */
    class RequestPermissions(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            val event = parameters["event"] as? String ?: "Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult"

            Log.d("Geolocation.RequestPermissions", "[Geolocation] Requesting location permissions with id: ${id ?: "none"}, event: $event")

            Handler(Looper.getMainLooper()).post {
                try {
                    val coord = GeolocationCoordinator.install(activity)
                    coord.launchLocationPermissionRequest(id, event)
                    Log.d("Geolocation.RequestPermissions", "[Geolocation] Permission request launched")
                } catch (e: Exception) {
                    Log.e("Geolocation.RequestPermissions", "[Geolocation] Error: ${e.message}", e)
                }
            }

            return emptyMap()
        }
    }
}