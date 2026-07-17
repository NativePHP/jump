package com.nativephp.geolocation

import android.Manifest
import android.app.ActivityManager
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.IBinder
import android.util.Log
import androidx.core.content.ContextCompat
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.nativephp.mobile.lifecycle.NativePHPLifecycle
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.time.Instant
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Foreground service backing Geolocation.StartBackgroundWatch — keeps one
 * location watch streaming while the app is backgrounded, or after its
 * process is dead entirely.
 *
 * Two delivery paths per fix:
 *  - The JSONL buffer under filesDir, ALWAYS. This is the source of truth;
 *    PHP drains it by byte cursor (Geolocation.DrainWatchBuffer) whenever
 *    it comes back to the foreground.
 *  - The live event stream (same NativeElementBridge path as the
 *    foreground coordinator's watches), best-effort. When the PHP runtime
 *    is alive this feeds real-time UI; when it isn't, the send fails or
 *    stalls and the buffer still has the fix.
 *
 * ALL location work runs on a private HandlerThread, never the main
 * thread. The event-channel write shares a mutex with the PHP consumer
 * and can stall when no runtime is draining it, and in a START_STICKY /
 * boot-receiver restart the process has no activity, no booted runtime,
 * and possibly no loaded JNI lib — main-thread involvement here is how
 * you get an "executing service" ANR. onStartCommand only posts work and
 * calls startForeground(), then returns.
 *
 * The watch config is persisted to SharedPreferences on start and cleared
 * on stop, so [LocationWatchBootReceiver] can re-arm after a reboot and
 * START_STICKY can re-arm after process death.
 */
class LocationWatchService : Service() {

    private var callback: LocationCallback? = null
    private lateinit var workerThread: HandlerThread
    private lateinit var worker: Handler
    private lateinit var lifecycleHandler: Handler
    private var appWasBackgrounded = false
    private val notifyAppEnteredForeground = Runnable { emitAppEnteredForeground() }
    private val appPausedListener: (Map<String, Any>) -> Unit = {
        lifecycleHandler.removeCallbacks(notifyAppEnteredForeground)
        appWasBackgrounded = true
    }
    private val appResumedListener: (Map<String, Any>) -> Unit = {
        lifecycleHandler.removeCallbacks(notifyAppEnteredForeground)
        if (appWasBackgrounded) {
            appWasBackgrounded = false
            lifecycleHandler.postDelayed(notifyAppEnteredForeground, 500L)
        }
    }

    // ── Share (live-location beacon) state — worker thread only ──
    @Volatile private var lastLocation: android.location.Location? = null
    @Volatile private var activeConfig: WatchConfig? = null
    private var shareRunnable: Runnable? = null
    private var firstShareSent = false
    private val shareInFlight = AtomicBoolean(false)
    @Volatile private var shareTerminated = false
    private lateinit var uploader: ExecutorService

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        workerThread = HandlerThread("nphp-location-watch").also { it.start() }
        worker = Handler(workerThread.looper)
        lifecycleHandler = Handler(mainLooper)
        NativePHPLifecycle.on(NativePHPLifecycle.Events.ON_PAUSE, appPausedListener)
        NativePHPLifecycle.on(NativePHPLifecycle.Events.ON_RESUME, appResumedListener)
        uploader = Executors.newSingleThreadExecutor { r -> Thread(r, "nphp-location-share") }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        // Config from the start intent, or from persistence on a sticky /
        // boot re-arm restart (null intent).
        val config = intent?.takeIf { it.action == ACTION_START }?.let { configFrom(it) }
            ?: persistedWatch(this)

        if (config == null) {
            stopSelf()
            return START_NOT_STICKY
        }

        // Starting a location-type FGS without the location runtime
        // permission is a SecurityException on API 34+ — check BEFORE
        // startForeground, and clear the persisted config so sticky/boot
        // re-arms don't loop into the same wall.
        if (!hasLocationPermission()) {
            Log.w(TAG, "Location permission not granted — background watch '${config.id}' cannot start")
            clearPersistedWatch(this)
            stopSelf()
            return START_NOT_STICKY
        }

        persistWatch(this, config)
        // The one thing that must happen promptly in this callback: the OS
        // gives a started-foreground service a short window to post its
        // notification.
        if (!startInForeground()) {
            clearPersistedWatch(this)
            stopSelf()
            return START_NOT_STICKY
        }
        worker.post { startStreaming(config) }

        return START_STICKY
    }

    override fun onDestroy() {
        NativePHPLifecycle.off(NativePHPLifecycle.Events.ON_PAUSE, appPausedListener)
        NativePHPLifecycle.off(NativePHPLifecycle.Events.ON_RESUME, appResumedListener)
        lifecycleHandler.removeCallbacks(notifyAppEnteredForeground)
        shareTerminated = true
        callback?.let {
            LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(it)
        }
        callback = null
        shareRunnable?.let { worker.removeCallbacks(it) }
        shareRunnable = null
        uploader.shutdown()
        workerThread.quitSafely()
        super.onDestroy()
    }

    private fun configFrom(intent: Intent): WatchConfig? {
        val id = intent.getStringExtra(EXTRA_ID) ?: return null
        return WatchConfig(
            id = id,
            eventClass = intent.getStringExtra(EXTRA_EVENT)
                ?: "Native\\Mobile\\Events\\Geolocation\\LocationUpdated",
            intervalMs = intent.getLongExtra(EXTRA_INTERVAL_MS, 5000L),
            minDistanceMeters = intent.getFloatExtra(EXTRA_MIN_DISTANCE, 0f),
            fineAccuracy = intent.getBooleanExtra(EXTRA_FINE_ACCURACY, false),
            shareUrl = intent.getStringExtra(EXTRA_SHARE_URL),
            shareToken = intent.getStringExtra(EXTRA_SHARE_TOKEN),
            shareIntervalMs = intent.getLongExtra(EXTRA_SHARE_INTERVAL_MS, 60_000L),
            shareExpiresAtMs = intent.getLongExtra(EXTRA_SHARE_EXPIRES_AT_MS, 0L),
            stopWatchOnExpiry = intent.getBooleanExtra(EXTRA_STOP_WATCH_ON_EXPIRY, false),
        )
    }

    private fun startInForeground(): Boolean {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL_ID, "Location tracking", NotificationManager.IMPORTANCE_LOW)
            )
        }

        val notification: Notification = Notification.Builder(this, CHANNEL_ID)
            .setContentTitle("Tracking location")
            .setSmallIcon(applicationInfo.icon)
            .setOngoing(true)
            .build()

        // FGS eligibility has corner cases beyond the permission we
        // preflight (background-start exemptions, OEM policies) — a
        // rejection here must degrade to "watch didn't start", never
        // crash the app.
        return try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
            } else {
                startForeground(NOTIFICATION_ID, notification)
            }
            true
        } catch (t: Throwable) {
            Log.e(TAG, "startForeground rejected: ${t.message}")
            false
        }
    }

    /** Runs on the worker thread. */
    private fun startStreaming(config: WatchConfig) {
        if (callback != null) return
        if (!hasLocationPermission()) {
            Log.w(TAG, "Location permission missing; background watch '${config.id}' not started")
            stopSelf()
            return
        }

        val priority = if (config.fineAccuracy) {
            Priority.PRIORITY_HIGH_ACCURACY
        } else {
            Priority.PRIORITY_BALANCED_POWER_ACCURACY
        }
        val interval = config.intervalMs.coerceAtLeast(1000L)
        val request = LocationRequest.Builder(priority, interval)
            .setMinUpdateIntervalMillis(interval / 2)
            .setMinUpdateDistanceMeters(config.minDistanceMeters)
            .setWaitForAccurateLocation(false)
            .build()

        val cb = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                result.locations.forEach { fix ->
                    val payload = fixPayload(config.id, fix)
                    // Buffer FIRST — the live forward below may fail or
                    // stall, and the fix must survive either way.
                    appendToBuffer(config.id, payload)
                    lastLocation = fix
                    // Beacon the very first fix immediately so the server
                    // learns the position seconds after start, not a full
                    // share interval later.
                    if (config.isSharing && !firstShareSent) {
                        firstShareSent = true
                        shareLatestFix(config)
                        scheduleNextShare(config)
                    }
                    forwardLive(config.eventClass, payload)
                }
            }
        }
        callback = cb

        try {
            LocationServices.getFusedLocationProviderClient(this)
                .requestLocationUpdates(request, cb, workerThread.looper)
            activeConfig = config
            shareTerminated = false
            if (config.isSharing) startShareLoop(config)
            Log.d(TAG, "Background watch '${config.id}' streaming" + if (config.isSharing) " + sharing" else "")
        } catch (e: SecurityException) {
            Log.e(TAG, "requestLocationUpdates rejected: ${e.message}")
            callback = null
            stopSelf()
        }
    }

    // ── Share (live-location beacon) ───────────────────────────────
    //
    // One POST of the LATEST fix every shareIntervalMs. Beacon semantics:
    // a failed POST is dropped — the next tick sends a fresher fix, and
    // the JSONL buffer remains the authoritative history. No retry queue
    // by design. HTTP runs on its own single-thread executor so a slow
    // request can never delay fix processing on the worker thread.

    /** Runs on the worker thread. */
    private fun startShareLoop(config: WatchConfig) {
        if (shareExpired(config)) {
            handleShareExpiry(config)
            return
        }

        scheduleNextShare(config)
    }

    /** Schedule relative to the previous attempt, including the immediate first fix. */
    private fun scheduleNextShare(config: WatchConfig) {
        shareRunnable?.let { worker.removeCallbacks(it) }
        val tick = object : Runnable {
            override fun run() {
                if (shareExpired(config)) {
                    handleShareExpiry(config)
                    return
                }
                shareLatestFix(config)
                scheduleNextShare(config)
            }
        }
        shareRunnable = tick
        worker.postDelayed(tick, config.shareIntervalMs)
    }

    private fun shareExpired(config: WatchConfig): Boolean =
        config.shareExpiresAtMs > 0L && System.currentTimeMillis() >= config.shareExpiresAtMs

    /**
     * Expiry: stop the beacon. With stopWatchOnExpiry the whole watch stops
     * too — recording ends and the persisted config is cleared, but the
     * buffer file is KEPT (frozen) for the app to drain on next open.
     * Without it, recording continues; the persisted config is rewritten
     * without the share fields so a sticky/boot re-arm doesn't resurrect
     * an expired beacon.
     */
    private fun handleShareExpiry(config: WatchConfig) {
        Log.d(TAG, "Share for watch '${config.id}' expired")
        shareTerminated = true
        persistShareResult(this, config.id, 0L, SHARE_STATUS_EXPIRED)
        shareRunnable?.let { worker.removeCallbacks(it) }
        shareRunnable = null

        if (config.stopWatchOnExpiry) {
            stop(this, preserveShareResult = true)
        } else {
            val recordingOnly = config.copy(
                shareUrl = null, shareToken = null, shareExpiresAtMs = 0L, stopWatchOnExpiry = false
            )
            activeConfig = recordingOnly
            persistWatch(this, recordingOnly)
        }
    }

    private fun shareLatestFix(config: WatchConfig) {
        val url = config.shareUrl ?: return
        val fix = lastLocation ?: return
        if (!shareInFlight.compareAndSet(false, true)) return

        val body = JSONObject().apply {
            put("points", org.json.JSONArray().put(sharePointPayload(fix)))
        }.toString()

        uploader.execute {
            var status = 0
            try {
                val connection = URL(url).openConnection() as HttpURLConnection
                connection.requestMethod = "POST"
                connection.connectTimeout = 15_000
                connection.readTimeout = 15_000
                connection.doOutput = true
                connection.setRequestProperty("Content-Type", "application/json")
                connection.setRequestProperty("Accept", "application/json")
                config.shareToken?.let { connection.setRequestProperty("Authorization", "Bearer $it") }
                connection.outputStream.use { it.write(body.toByteArray()) }
                status = connection.responseCode
                connection.disconnect()
                Log.d(TAG, "Share POST -> $status")
            } catch (t: Throwable) {
                // Beacon dropped (offline, DNS, timeout…) — next tick sends a fresher fix.
                Log.d(TAG, "Share POST dropped: ${t.javaClass.simpleName}")
            } finally {
                if (!shareTerminated) {
                    persistShareResult(this, config.id, System.currentTimeMillis(), status)
                }
                shareInFlight.set(false)
            }
        }
    }

    /** Single point in the wire shape servers expect ({"points":[...]}) — ISO-8601 UTC timestamp. */
    private fun sharePointPayload(fix: android.location.Location): JSONObject =
        JSONObject().apply {
            put("latitude", fix.latitude)
            put("longitude", fix.longitude)
            put("accuracy", fix.accuracy)
            if (fix.hasSpeed()) put("speed", fix.speed)
            if (fix.hasBearing()) put("heading", fix.bearing)
            if (fix.hasAltitude()) put("altitude", fix.altitude)
            put("timestamp", Instant.ofEpochMilli(fix.time).toString())
        }

    /**
     * Best-effort live event for foreground UI — and ONLY for foreground
     * UI. Events posted while no activity is visible keep the PHP element
     * runloop alive in a headless process (every fix wakes it, the mounted
     * component republishes a tree nobody renders), which then wedges the
     * old activity's destroy handshake when the app reopens — the "stuck
     * at boot screen" failure. When the UI is gone, the buffer alone
     * carries the stream; PHP re-attaches through Status/Drain.
     *
     * Throwable, not Exception: in a restarted process without the
     * runtime, the JNI stub isn't loaded and this throws
     * UnsatisfiedLinkError. Once it hard-fails, stop trying for the
     * lifetime of this process.
     */
    private var liveChannelBroken = false

    private fun forwardLive(eventClass: String, payload: JSONObject) {
        if (liveChannelBroken || !uiVisible()) return
        try {
            NativeElementBridge.sendNativeEvent(eventClass, payload.toString())
        } catch (t: Throwable) {
            liveChannelBroken = true
            Log.d(TAG, "Live channel unavailable; buffering only (${t.javaClass.simpleName})")
        }
    }

    private fun emitAppEnteredForeground() {
        val config = activeConfig ?: persistedWatch(this) ?: return
        Log.d(TAG, "App entered foreground; notifying watch '${config.id}'")
        forwardLive(APP_ENTERED_FOREGROUND_EVENT, JSONObject().apply {
            put("id", config.id)
            put("timestamp", System.currentTimeMillis())
        })
    }

    /**
     * True only when the app has a visible activity. With a foreground
     * service running, a UI-less process reports IMPORTANCE_FOREGROUND_SERVICE
     * (125); an actually-visible UI reports IMPORTANCE_FOREGROUND (100).
     */
    private fun uiVisible(): Boolean {
        val state = ActivityManager.RunningAppProcessInfo()
        ActivityManager.getMyMemoryState(state)
        return state.importance <= ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND
    }

    /** Same wire shape as the foreground coordinator's watch events. */
    private fun fixPayload(watchId: String, fix: android.location.Location): JSONObject =
        JSONObject().apply {
            put("success", true)
            put("latitude", fix.latitude)
            put("longitude", fix.longitude)
            put("accuracy", fix.accuracy)
            if (fix.hasSpeed()) put("speed", fix.speed) else put("speed", JSONObject.NULL)
            if (fix.hasBearing()) put("heading", fix.bearing) else put("heading", JSONObject.NULL)
            if (fix.hasAltitude()) put("altitude", fix.altitude)
            put("timestamp", fix.time)
            put("provider", fix.provider ?: "fused")
            put("error", false)
            put("id", watchId)
        }

    private fun appendToBuffer(watchId: String, payload: JSONObject) {
        try {
            bufferFile(this, watchId).appendText(payload.toString() + "\n")
        } catch (e: Exception) {
            Log.e(TAG, "buffer append failed: ${e.message}")
        }
    }

    private fun hasLocationPermission(): Boolean =
        ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

    data class WatchConfig(
        val id: String,
        val eventClass: String,
        val intervalMs: Long,
        val minDistanceMeters: Float,
        val fineAccuracy: Boolean,
        // Share (live-location beacon) — null shareUrl means recording only.
        val shareUrl: String? = null,
        val shareToken: String? = null,
        val shareIntervalMs: Long = 60_000L,
        val shareExpiresAtMs: Long = 0L,
        val stopWatchOnExpiry: Boolean = false,
    ) {
        val isSharing: Boolean get() = !shareUrl.isNullOrEmpty()
    }

    companion object {
        private const val TAG = "Geolocation.BgWatch"
        private const val CHANNEL_ID = "nativephp_location_watch"
        private const val NOTIFICATION_ID = 0x10c
        private const val PREFS = "nativephp_location_watch"
        private const val APP_ENTERED_FOREGROUND_EVENT = "NativePHP\\Geolocation\\Events\\AppEnteredForeground"

        const val ACTION_START = "com.nativephp.geolocation.watch.START"
        const val EXTRA_ID = "id"
        const val EXTRA_EVENT = "event"
        const val EXTRA_INTERVAL_MS = "interval_ms"
        const val EXTRA_MIN_DISTANCE = "min_distance"
        const val EXTRA_FINE_ACCURACY = "fine_accuracy"
        const val EXTRA_SHARE_URL = "share_url"
        const val EXTRA_SHARE_TOKEN = "share_token"
        const val EXTRA_SHARE_INTERVAL_MS = "share_interval_ms"
        const val EXTRA_SHARE_EXPIRES_AT_MS = "share_expires_at_ms"
        const val EXTRA_STOP_WATCH_ON_EXPIRY = "stop_watch_on_expiry"

        /** Sentinel status codes for share results (persisted for Status). */
        const val SHARE_STATUS_EXPIRED = -1

        /** Start (or re-arm) the background watch. */
        fun start(context: Context, config: WatchConfig) {
            val intent = Intent(context, LocationWatchService::class.java)
                .setAction(ACTION_START)
                .putExtra(EXTRA_ID, config.id)
                .putExtra(EXTRA_EVENT, config.eventClass)
                .putExtra(EXTRA_INTERVAL_MS, config.intervalMs)
                .putExtra(EXTRA_MIN_DISTANCE, config.minDistanceMeters)
                .putExtra(EXTRA_FINE_ACCURACY, config.fineAccuracy)
                .putExtra(EXTRA_SHARE_URL, config.shareUrl)
                .putExtra(EXTRA_SHARE_TOKEN, config.shareToken)
                .putExtra(EXTRA_SHARE_INTERVAL_MS, config.shareIntervalMs)
                .putExtra(EXTRA_SHARE_EXPIRES_AT_MS, config.shareExpiresAtMs)
                .putExtra(EXTRA_STOP_WATCH_ON_EXPIRY, config.stopWatchOnExpiry)
            ContextCompat.startForegroundService(context, intent)
        }

        /**
         * Stop the background watch and clear its persisted config.
         * stopService() (not a start intent) so this also works from a
         * backgrounded app, where startService() throws.
         */
        fun stop(context: Context, preserveShareResult: Boolean = false) {
            clearPersistedWatch(context, clearShareResult = !preserveShareResult)
            context.stopService(Intent(context, LocationWatchService::class.java))
        }

        fun bufferFile(context: Context, watchId: String): File {
            // noBackupFilesDir: a location trail must not ride along in
            // Android Auto Backup — same persistence guarantees as
            // filesDir, just excluded from backups.
            val dir = File(context.noBackupFilesDir, "nativephp_location")
            if (!dir.exists()) {
                dir.mkdirs()
                // One-time migration from the old (backed-up) location.
                val legacy = File(context.filesDir, "nativephp_location")
                if (legacy.isDirectory) {
                    legacy.listFiles()?.forEach { it.renameTo(File(dir, it.name)) }
                    legacy.delete()
                }
            }
            // Watch ids are UUIDs from PHP; sanitize anyway since this names a file.
            return File(dir, watchId.replace(Regex("[^A-Za-z0-9_-]"), "_") + ".jsonl")
        }

        fun persistWatch(context: Context, config: WatchConfig) {
            // NOTE: the bearer token is stored in plain SharedPreferences —
            // parity with the config's other fields. Use short-lived tokens;
            // EncryptedSharedPreferences is a tracked follow-up.
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putString("id", config.id)
                .putString("event", config.eventClass)
                .putLong("interval_ms", config.intervalMs)
                .putFloat("min_distance", config.minDistanceMeters)
                .putBoolean("fine_accuracy", config.fineAccuracy)
                .putString("share_url", config.shareUrl)
                .putString("share_token", config.shareToken)
                .putLong("share_interval_ms", config.shareIntervalMs)
                .putLong("share_expires_at_ms", config.shareExpiresAtMs)
                .putBoolean("stop_watch_on_expiry", config.stopWatchOnExpiry)
                .apply()
        }

        fun persistedWatch(context: Context): WatchConfig? {
            val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            val id = prefs.getString("id", null) ?: return null
            return WatchConfig(
                id = id,
                eventClass = prefs.getString("event", null)
                    ?: "Native\\Mobile\\Events\\Geolocation\\LocationUpdated",
                intervalMs = prefs.getLong("interval_ms", 5000L),
                minDistanceMeters = prefs.getFloat("min_distance", 0f),
                fineAccuracy = prefs.getBoolean("fine_accuracy", false),
                shareUrl = prefs.getString("share_url", null),
                shareToken = prefs.getString("share_token", null),
                shareIntervalMs = prefs.getLong("share_interval_ms", 60_000L),
                shareExpiresAtMs = prefs.getLong("share_expires_at_ms", 0L),
                stopWatchOnExpiry = prefs.getBoolean("stop_watch_on_expiry", false),
            )
        }

        fun clearPersistedWatch(context: Context, clearShareResult: Boolean = true) {
            val editor = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .remove("id")
                .remove("event")
                .remove("interval_ms")
                .remove("min_distance")
                .remove("fine_accuracy")
                .remove("share_url")
                .remove("share_token")
                .remove("share_interval_ms")
                .remove("share_expires_at_ms")
                .remove("stop_watch_on_expiry")

            if (clearShareResult) {
                editor
                    .remove("share_last_watch_id")
                    .remove("share_last_at_ms")
                    .remove("share_last_status")
            }

            editor.apply()
        }

        /** Last share outcome, readable by BackgroundWatchStatus. Status 0 = network failure, -1 = expired. */
        fun persistShareResult(context: Context, watchId: String, atMs: Long, status: Int) {
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putString("share_last_watch_id", watchId)
                .putLong("share_last_at_ms", atMs)
                .putInt("share_last_status", status)
                .apply()
        }

        data class ShareResult(val watchId: String, val atMs: Long, val status: Int)

        fun lastShareResult(context: Context): ShareResult? {
            val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            if (!prefs.contains("share_last_status")) return null
            return ShareResult(
                watchId = prefs.getString("share_last_watch_id", null) ?: return null,
                atMs = prefs.getLong("share_last_at_ms", 0L),
                status = prefs.getInt("share_last_status", 0),
            )
        }

        fun clearShareResult(context: Context) {
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .remove("share_last_watch_id")
                .remove("share_last_at_ms")
                .remove("share_last_status")
                .apply()
        }
    }
}
