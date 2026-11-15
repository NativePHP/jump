package com.nativephp.jumpclient

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Functions for running a Jump session against a `native:jump` dev server.
 * Namespace: "JumpClient.*"
 */
object JumpClientFunctions {

    private const val TAG = "JumpClientFunctions"

    // MARK: - JumpClient.Connect

    /**
     * Connect to a dev server by host + port. Drives [JumpBridgeRelay], which
     * opens the WebSocket to the dev server and streams its native UI into
     * `NativeUIBridge` (rendered in place by the shell's existing renderers).
     * Mirrors iOS `JumpClientFunctions.Connect`.
     */
    class Connect(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val host = parameters["host"] as? String
            val port = parameters["port"] as? String
            if (host == null || port == null) {
                Log.e(TAG, "❌ JumpClient.Connect missing host/port: $parameters")
                return emptyMap()
            }
            Log.i(TAG, "🔌 JumpClient.Connect → $host:$port")
            Handler(Looper.getMainLooper()).post {
                EscapeHatchGesture.install(activity)
                // Activity is threaded through for the webview-forward swap
                // (MainActivity.jumpWebViewSwap) when the remote is a
                // WebView app.
                JumpBridgeRelay.connect(host, port, activity)
            }
            return emptyMap()
        }
    }
}
