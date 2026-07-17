package com.nativephp.geolocation

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Re-arms a persisted background location watch after the device reboots.
 *
 * A watch that survives component teardown must also survive a reboot
 * (ambient tracking dies mid-drive otherwise). The service persists its
 * config on START and clears it on STOP; if a config is present at boot,
 * the stream is still logically "on" and we restart the foreground service
 * without waking the PHP runtime.
 */
class LocationWatchBootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return

        val config = LocationWatchService.persistedWatch(context) ?: return

        try {
            LocationWatchService.start(context, config)
        } catch (e: Exception) {
            // FGS-from-boot can be restricted by OEMs / newer API levels;
            // the watch re-arms on next app launch via the persisted config.
            Log.w("Geolocation.BgWatch", "boot re-arm failed: ${e.message}")
        }
    }
}
