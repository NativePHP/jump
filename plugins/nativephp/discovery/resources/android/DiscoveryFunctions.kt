package com.nativephp.discovery

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Functions for browsing the LAN for `_jump._tcp` dev servers.
 * Namespace: "Discovery.*"
 */
object DiscoveryFunctions {

    private const val TAG = "DiscoveryFunctions"

    /** One browser for the app lifetime; Start/Stop drive it. */
    private var discovery: NsdServerDiscovery? = null

    private fun ensure(activity: FragmentActivity): NsdServerDiscovery {
        return discovery ?: NsdServerDiscovery(activity).also { discovery = it }
    }

    // MARK: - Discovery.Start

    /**
     * Start browsing for dev servers. Fires `ServerFound` / `ServerLost`
     * events as servers appear and disappear.
     */
    class Start(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            Log.i(TAG, "🛰️ Discovery.Start")
            Handler(Looper.getMainLooper()).post {
                EscapeHatchGesture.install(activity)
                ensure(activity).start()
            }
            return emptyMap()
        }
    }

    // MARK: - Discovery.Stop

    /** Stop browsing and tear down the native browser. */
    class Stop(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            Log.i(TAG, "🛰️ Discovery.Stop")
            Handler(Looper.getMainLooper()).post {
                discovery?.stop()
            }
            return emptyMap()
        }
    }

    // MARK: - Discovery.Connect

    /**
     * Connect to a discovered dev server by host + port. Drives the ported
     * [JumpBridgeRelay], which opens the WebSocket to the dev server and
     * streams its native UI into `NativeUIBridge` (rendered in place by the
     * shell's existing renderers). Mirrors iOS `DiscoveryFunctions.Connect`.
     */
    class Connect(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val host = parameters["host"] as? String
            val port = parameters["port"] as? String
            if (host == null || port == null) {
                Log.e(TAG, "❌ Discovery.Connect missing host/port: $parameters")
                return emptyMap()
            }
            Log.i(TAG, "🔌 Discovery.Connect → $host:$port")
            Handler(Looper.getMainLooper()).post {
                EscapeHatchGesture.install(activity)
                JumpBridgeRelay.connect(host, port)
            }
            return emptyMap()
        }
    }
}
