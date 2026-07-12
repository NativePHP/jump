package com.nativephp.discovery

import android.content.Context
import android.net.nsd.NsdManager
import android.net.nsd.NsdServiceInfo
import android.net.wifi.WifiManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

/** A Jump dev server discovered on the LAN. host+port match what the QR encodes. */
data class DiscoveredServer(
    val name: String,
    val host: String,
    val port: Int,
)

/**
 * Discovers running Jump dev servers on the local network via mDNS / Android NSD
 * (service type `_jump._tcp`). As servers appear and disappear it dispatches
 * Laravel events (`ServerFound` / `ServerLost`) into PHP so a NativeComponent
 * can react with `#[OnNative(...)]`.
 *
 * The dev server advertises the SAME host+port the QR encodes (see
 * JumpCommand::advertiseOnNetwork), so a tap can feed the exact same connect
 * flow — this class only surfaces the list.
 */
class NsdServerDiscovery(private val activity: FragmentActivity) {

    private val appContext: Context = activity.applicationContext
    private val nsdManager = appContext.getSystemService(Context.NSD_SERVICE) as NsdManager

    private var discoveryListener: NsdManager.DiscoveryListener? = null
    private var multicastLock: WifiManager.MulticastLock? = null

    // Servers we've told PHP about, keyed by "host:port", so we emit each
    // ServerFound / ServerLost exactly once.
    private val emitted = HashMap<String, DiscoveredServer>()

    // NsdManager allows only one resolveService() in flight at a time, so queue.
    private val resolveQueue = ArrayDeque<NsdServiceInfo>()
    private var resolving = false

    fun start() {
        Log.i(TAG, "start() called")
        if (discoveryListener != null) return

        // mDNS rides on multicast, which Android may drop unless a lock is held.
        try {
            val wifi = appContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
            multicastLock = wifi.createMulticastLock("jump-nsd").apply {
                setReferenceCounted(true)
                acquire()
            }
        } catch (e: Exception) {
            Log.w(TAG, "multicast lock failed: ${e.message}")
        }

        val listener = object : NsdManager.DiscoveryListener {
            override fun onDiscoveryStarted(serviceType: String) {
                Log.i(TAG, "discovery started for $serviceType")
            }
            override fun onServiceFound(info: NsdServiceInfo) {
                Log.i(TAG, "found: ${info.serviceName} type=${info.serviceType}")
                enqueueResolve(info)
            }
            override fun onServiceLost(info: NsdServiceInfo) {
                Log.i(TAG, "lost: ${info.serviceName}")
                // NSD gives us only the service name on loss; drop any emitted
                // server whose advertised name matches.
                val gone = emitted.values.filter { it.name == info.serviceName }
                for (server in gone) {
                    emitted.remove("${server.host}:${server.port}")
                    dispatchLost(server)
                }
            }
            override fun onDiscoveryStopped(serviceType: String) {}
            override fun onStartDiscoveryFailed(serviceType: String, errorCode: Int) {
                Log.w(TAG, "start discovery FAILED: code=$errorCode type=$serviceType")
            }
            override fun onStopDiscoveryFailed(serviceType: String, errorCode: Int) {}
        }
        discoveryListener = listener
        try {
            nsdManager.discoverServices(SERVICE_TYPE, NsdManager.PROTOCOL_DNS_SD, listener)
        } catch (e: Exception) {
            Log.w(TAG, "discoverServices threw: ${e.message}")
        }
    }

    fun stop() {
        discoveryListener?.let {
            try { nsdManager.stopServiceDiscovery(it) } catch (_: Exception) {}
        }
        discoveryListener = null
        multicastLock?.let { if (it.isHeld) try { it.release() } catch (_: Exception) {} }
        multicastLock = null

        // Tell PHP everything went away so the UI clears.
        for (server in emitted.values.toList()) {
            dispatchLost(server)
        }
        emitted.clear()
        resolveQueue.clear()
        resolving = false
    }

    @Synchronized
    private fun enqueueResolve(info: NsdServiceInfo) {
        resolveQueue.addLast(info)
        pumpResolve()
    }

    // `resolveService(info, listener)` is deprecated on API 34+ in favor of
    // `registerServiceInfoCallback`, but that's a *persistent* callback model
    // (fires on every update until unregistered) — a poor fit for this one-shot,
    // serialized resolve queue. We keep the one-shot resolve and suppress the
    // warning deliberately; the host lookup below IS modernized (hostAddresses).
    @Synchronized
    @Suppress("DEPRECATION")
    private fun pumpResolve() {
        if (resolving) return
        val next = resolveQueue.removeFirstOrNull() ?: return
        resolving = true
        try {
            nsdManager.resolveService(next, object : NsdManager.ResolveListener {
                override fun onResolveFailed(info: NsdServiceInfo, errorCode: Int) {
                    Log.w(TAG, "resolve FAILED: ${info.serviceName} code=$errorCode")
                    finishResolve()
                }
                override fun onServiceResolved(info: NsdServiceInfo) {
                    Log.i(TAG, "resolved: ${info.serviceName} -> ${hostAddressOf(info)}:${info.port}")
                    // Prefer the explicit LAN IPv4 from the `host` TXT record over
                    // the resolved address, which can be link-local IPv6 / .local.
                    val txt = info.attributes ?: emptyMap()
                    val txtHost = txt["host"]?.toString(Charsets.UTF_8)?.takeIf { it.isNotBlank() }
                    val host = txtHost ?: hostAddressOf(info)
                    if (host != null) {
                        val label = txt["name"]?.toString(Charsets.UTF_8)?.takeIf { it.isNotBlank() }
                            ?: info.serviceName
                        val server = DiscoveredServer(label, host, info.port)
                        val key = "${server.host}:${server.port}"
                        if (emitted[key] != server) {
                            emitted[key] = server
                            dispatchFound(server)
                        }
                    }
                    finishResolve()
                }
            })
        } catch (e: Exception) {
            Log.w(TAG, "resolveService threw: ${e.message}")
            finishResolve()
        }
    }

    @Synchronized
    private fun finishResolve() {
        resolving = false
        pumpResolve()
    }

    /**
     * Resolved host address. `NsdServiceInfo.getHost()` is deprecated on API 34+
     * (a service can resolve to multiple addresses) — use `getHostAddresses()`
     * there and fall back to the single-address getter on older devices.
     */
    @Suppress("DEPRECATION")
    private fun hostAddressOf(info: NsdServiceInfo): String? =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            info.hostAddresses.firstOrNull()?.hostAddress
        } else {
            info.host?.hostAddress
        }

    // MARK: - Event dispatch

    // NSD resolve callbacks fire on a background (ConnectivityThread) looper,
    // but NativeActionCoordinator.dispatchEvent calls WebView.evaluateJavascript,
    // which crashes unless invoked on the main thread — so hop to main here.
    private fun dispatchFound(server: DiscoveredServer) {
        val payload = JSONObject()
            .put("host", server.host)
            .put("port", server.port.toString())
            .put("name", server.name)
        Handler(Looper.getMainLooper()).post {
            NativeActionCoordinator.dispatchEvent(activity, SERVER_FOUND_EVENT, payload.toString())
        }
    }

    private fun dispatchLost(server: DiscoveredServer) {
        val payload = JSONObject()
            .put("host", server.host)
            .put("port", server.port.toString())
        Handler(Looper.getMainLooper()).post {
            NativeActionCoordinator.dispatchEvent(activity, SERVER_LOST_EVENT, payload.toString())
        }
    }

    companion object {
        private const val TAG = "NsdDiscovery"
        // No trailing dot — the most reliable form across Android versions for
        // NsdManager.discoverServices (a trailing dot can silently fail).
        private const val SERVICE_TYPE = "_jump._tcp"
        private const val SERVER_FOUND_EVENT = "NativePHP\\Discovery\\Events\\ServerFound"
        private const val SERVER_LOST_EVENT = "NativePHP\\Discovery\\Events\\ServerLost"
    }
}
