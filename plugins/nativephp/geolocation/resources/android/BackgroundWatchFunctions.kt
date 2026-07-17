package com.nativephp.geolocation

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.RandomAccessFile

/**
 * Background-watch bridge functions for NativePHP.
 * Namespace: "Geolocation.StartBackgroundWatch" / "StopBackgroundWatch" /
 * "DrainWatchBuffer" / "BackgroundWatchStatus".
 *
 * Unlike the coordinator-backed foreground watch (WatchPosition), these
 * drive [LocationWatchService] — a foreground service that outlives the
 * activity, the PHP runtime, and (via the boot receiver) the OS session.
 */
object BackgroundWatchFunctions {

    /**
     * Start a background location watch.
     * Parameters:
     *   - id: string (required) - Watch identifier; buffered fixes and live events carry it
     *   - event: string (optional) - Event class dispatched per live update
     *   - fineAccuracy: boolean (optional) - High accuracy (GPS) vs balanced
     *   - interval: number (optional) - Target ms between updates (default 5000)
     *   - minDistance: number (optional) - Meters moved before another update
     *   - shareUrl: string (optional) - POST the latest fix here on an interval (live-location beacon)
     *   - shareToken: string (optional) - Bearer token for the share POSTs
     *   - shareIntervalMs: number (optional) - Ms between share POSTs (default 60000)
     *   - shareExpiresAt: string (optional) - ISO-8601 moment after which sharing stops
     *   - stopWatchOnExpiry: boolean (optional) - Also stop recording at expiry (buffer kept)
     * Returns: { success: true } (fixes buffer natively; live events stream while the runtime is up)
     */
    class Start(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            if (id.isNullOrEmpty()) {
                return BridgeResponse.error(
                    "geolocation.missing_id",
                    "StartBackgroundWatch requires an id"
                )
            }

            val config = LocationWatchService.WatchConfig(
                id = id,
                eventClass = parameters["event"] as? String
                    ?: "Native\\Mobile\\Events\\Geolocation\\LocationUpdated",
                intervalMs = (parameters["interval"] as? Number)?.toLong() ?: 5000L,
                minDistanceMeters = (parameters["minDistance"] as? Number)?.toFloat() ?: 0f,
                fineAccuracy = parameters["fineAccuracy"] as? Boolean ?: false,
                shareUrl = (parameters["shareUrl"] as? String)?.takeIf { it.isNotBlank() },
                shareToken = (parameters["shareToken"] as? String)?.takeIf { it.isNotBlank() },
                shareIntervalMs = ((parameters["shareIntervalMs"] as? Number)?.toLong() ?: 60_000L)
                    .coerceAtLeast(5_000L),
                shareExpiresAtMs = parseIsoToEpochMs(parameters["shareExpiresAt"] as? String),
                stopWatchOnExpiry = parameters["stopWatchOnExpiry"] as? Boolean ?: false,
            )

            Log.d(TAG, "Starting background watch $id (interval: ${config.intervalMs}, minDistance: ${config.minDistanceMeters})")

            // A new user-initiated session must not expose the previous
            // session's terminal/result state.
            LocationWatchService.clearShareResult(activity)

            // Route through the coordinator: already granted → the service
            // starts immediately; never asked → the system permission
            // prompt shows and the watch starts on grant (denial dispatches
            // a watch error event). Starting a location FGS without the
            // permission is a SecurityException on API 34+, so the service
            // must never be started directly from here.
            Handler(Looper.getMainLooper()).post {
                try {
                    val coord = GeolocationCoordinator.install(activity)
                    coord.startBackgroundWatchWithPermission(config)
                } catch (e: Exception) {
                    Log.e(TAG, "StartBackgroundWatch failed: ${e.message}", e)
                }
            }

            return mapOf("success" to true)
        }
    }

    /**
     * Stop the background watch and clear its persisted config.
     * Parameters:
     *   - id: string (optional) - When given with clearBuffer, also deletes that watch's buffer
     *   - clearBuffer: boolean (optional) - Delete the buffered fixes too (default false,
     *     so a final Drain after Stop still sees the tail of the stream)
     */
    class Stop(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            val clearBuffer = parameters["clearBuffer"] as? Boolean ?: false

            Log.d(TAG, "Stopping background watch${id?.let { " $it" } ?: ""}")
            LocationWatchService.stop(activity)

            if (clearBuffer && !id.isNullOrEmpty()) {
                LocationWatchService.bufferFile(activity, id).delete()
            }

            return mapOf("success" to true)
        }
    }

    /**
     * Drain buffered fixes from a byte cursor.
     * Parameters:
     *   - id: string (required) - The watch whose buffer to read
     *   - cursor: number (optional) - Byte offset from a previous drain (default 0)
     * Returns: { fixes: [...], cursor: <next byte offset>, size: <buffer bytes> }
     *
     * The cursor is a plain byte offset into the append-only JSONL buffer;
     * persist it (component property, session, cache) and pass it back to
     * read only what's new. A cursor past EOF (buffer was cleared) resets to 0.
     */
    class Drain(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            if (id.isNullOrEmpty()) {
                return BridgeResponse.error(
                    "geolocation.missing_id",
                    "DrainWatchBuffer requires an id"
                )
            }

            val file = LocationWatchService.bufferFile(activity, id)
            if (!file.exists()) {
                return mapOf("fixes" to JSONArray(), "cursor" to 0, "size" to 0)
            }

            var cursor = (parameters["cursor"] as? Number)?.toLong() ?: 0L
            val length = file.length()
            if (cursor < 0 || cursor > length) cursor = 0L

            val fixes = JSONArray()
            RandomAccessFile(file, "r").use { raf ->
                raf.seek(cursor)
                val bytes = ByteArray((length - cursor).toInt())
                raf.readFully(bytes)
                String(bytes, Charsets.UTF_8).lineSequence().forEach { line ->
                    if (line.isNotBlank()) {
                        try {
                            fixes.put(JSONObject(line))
                        } catch (e: Exception) {
                            Log.w(TAG, "Skipping malformed buffer line: ${e.message}")
                        }
                    }
                }
            }

            return mapOf("fixes" to fixes, "cursor" to length, "size" to length)
        }
    }

    /**
     * Drop buffered fixes BEFORE a byte offset — the reclaim half of the
     * drain → persist/upload → trim cycle. Pass the cursor a successful
     * drain returned; the drained prefix is discarded and offsets rebase
     * to 0 (the caller must reset its stored cursor to 0).
     * Parameters:
     *   - id: string (required)
     *   - upTo: number (required) - Byte offset from a prior drain
     * Returns: { success: true, size: <remaining bytes> }
     */
    class Trim(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            if (id.isNullOrEmpty()) {
                return BridgeResponse.error("geolocation.missing_id", "TrimWatchBuffer requires an id")
            }
            val upTo = (parameters["upTo"] as? Number)?.toLong() ?: 0L

            val file = LocationWatchService.bufferFile(activity, id)
            if (!file.exists() || upTo <= 0) {
                return mapOf("success" to true, "size" to (if (file.exists()) file.length() else 0L))
            }

            val length = file.length()
            if (upTo >= length) {
                // Whole buffer drained — truncate rather than delete so an
                // active watch keeps appending to the same file.
                RandomAccessFile(file, "rw").use { it.setLength(0) }
                return mapOf("success" to true, "size" to 0L)
            }

            // Rewrite the remainder atomically (temp file + rename) so a
            // concurrent append lands either before or after the swap but
            // never inside a half-written file.
            val remainder = RandomAccessFile(file, "r").use { raf ->
                raf.seek(upTo)
                ByteArray((length - upTo).toInt()).also { raf.readFully(it) }
            }
            val tmp = File(file.parentFile, file.name + ".tmp")
            tmp.writeBytes(remainder)
            if (!tmp.renameTo(file)) {
                tmp.delete()
                return BridgeResponse.error("geolocation.trim_failed", "Could not replace buffer file")
            }

            return mapOf("success" to true, "size" to file.length())
        }
    }

    /**
     * Report the persisted background watch, if any.
     * Returns: { active: bool, id?, event?, interval?, minDistance?, fineAccuracy?, bufferBytes?,
     *            shareActive?, shareIntervalMs?, shareExpiresAt?, lastShareAt?, lastShareStatus? }
     *
     * A freshly booted PHP runtime calls this to discover a watch that
     * survived it (background stream, reboot re-arm) and re-attach.
     * lastShareStatus: HTTP code, 0 = network failure, -1 = expired.
     */
    class Status(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val config = LocationWatchService.persistedWatch(activity)
            val lastShare = LocationWatchService.lastShareResult(activity)

            if (config == null) {
                return if (lastShare?.status == LocationWatchService.SHARE_STATUS_EXPIRED) {
                    buildMap {
                        put("active", false)
                        put("id", lastShare.watchId)
                        put("shareActive", false)
                        put("lastShareStatus", lastShare.status)
                    }
                } else {
                    mapOf("active" to false)
                }
            }

            return buildMap {
                put("active", true)
                put("id", config.id)
                put("event", config.eventClass)
                put("interval", config.intervalMs)
                put("minDistance", config.minDistanceMeters)
                put("fineAccuracy", config.fineAccuracy)
                put("bufferBytes", LocationWatchService.bufferFile(activity, config.id).length())
                put("shareActive", config.isSharing)
                put("shareIntervalMs", config.shareIntervalMs)
                if (config.shareExpiresAtMs > 0L) {
                    put("shareExpiresAt", java.time.Instant.ofEpochMilli(config.shareExpiresAtMs).toString())
                }
                if (lastShare != null && lastShare.watchId == config.id) {
                    if (lastShare.atMs > 0L) {
                        put("lastShareAt", java.time.Instant.ofEpochMilli(lastShare.atMs).toString())
                    }
                    put("lastShareStatus", lastShare.status)
                }
            }
        }
    }

    /** ISO-8601 → epoch ms; 0 = no expiry, 1 = invalid (fail closed as expired). */
    private fun parseIsoToEpochMs(iso: String?): Long {
        if (iso.isNullOrBlank()) return 0L
        return try {
            java.time.OffsetDateTime.parse(iso).toInstant().toEpochMilli()
        } catch (e: Exception) {
            try {
                java.time.Instant.parse(iso).toEpochMilli()
            } catch (e2: Exception) {
                Log.e(TAG, "Unparseable shareExpiresAt '$iso' — sharing disabled")
                1L
            }
        }
    }

    private const val TAG = "Geolocation.BgWatch"
}
