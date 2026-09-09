package com.nativephp.discovery

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunctionRegistry
import com.nativephp.mobile.network.JumpWebViewSession
import com.nativephp.mobile.ui.MainActivity
import com.nativephp.mobile.ui.nativerender.EventType
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.ui.nativerender.NativeUIBridge
import java.lang.ref.WeakReference
import okhttp3.Call
import okhttp3.Callback
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.json.JSONObject
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/**
 * WebSocket relay for connecting this app to a remote NativePHP dev server
 * (`php artisan native:jump`) and rendering its native UI in place.
 *
 * Mirror of the iOS `JumpBridgeRelay`. It receives bridge calls from the dev
 * machine, executes device APIs locally via the shell's existing
 * [BridgeFunctionRegistry], and routes `Element.*` frames through
 * [JumpElementRuntime] (which renders into [NativeUIBridge]).
 *
 * CRUCIAL: on socket-open it fires [driveRemoteApp] — a held-open `GET /` that
 * starts the dev server's native runloop. Without it the server never begins
 * publishing `Element.*` frames (this was the fix that made iOS render).
 */
object JumpBridgeRelay {

    private const val TAG = "JumpBridgeRelay"

    private var webSocket: WebSocket? = null
    private var client: OkHttpClient? = null
    private var host: String = ""
    private var port: String = ""
    private var wsPort: String = ""
    private var isListening = false

    var isConnected = false
        private set

    /** Fired on a live-reload signal for non-native (WebView) content. */
    var onReload: (() -> Unit)? = null
    var onConnected: (() -> Unit)? = null

    /**
     * Fired (main thread) when reconnection is abandoned — the dev server is
     * gone. The default action ([JumpElementRuntime.endSession]) clears the
     * remote session so the UI returns to the app's own home. Mirrors iOS.
     */
    var onSessionEnded: (() -> Unit)? = null

    // Reconnect bookkeeping: quick fixed retries, then give up (~3s).
    private var reconnectAttempt = 0
    private val maxReconnectAttempts = 3

    private val mainHandler = Handler(Looper.getMainLooper())

    // Element.WaitEvent blocks. A SINGLE thread is enough: each new WaitEvent
    // calls supersedeWaiter() first, releasing the previously-blocked waitEvent
    // so its thread frees before the next runs.
    private val waitExecutor = Executors.newSingleThreadExecutor()

    // Set while a native-session hot reload is in flight.
    @Volatile
    private var pendingHotReloadReExec = false

    // Held-open GET that drives the remote runloop (see driveRemoteApp).
    private var runloopCall: Call? = null
    private var runloopClient: OkHttpClient? = null

    // ─────────────────────────────────────────────────────────────────────
    // Connection
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Connect to a discovered dev server. Opens the WebSocket to the dev
     * server and streams its native UI into [NativeUIBridge]. Device-API and
     * plugin bridge functions are already registered by MainActivity at
     * startup (they need an Activity/Context the relay doesn't hold), so we
     * don't re-register here.
     */
    /** Activity handle for the webview-forward swap (jumpWebViewSwap). */
    private var activityRef: WeakReference<FragmentActivity>? = null

    fun connect(host: String, port: String, activity: FragmentActivity? = null) {
        disconnect()
        // A previous webview-forward session may still be live — disconnect()
        // deliberately leaves it alone (only exitToJump stops it), but a NEW
        // session must not inherit its forwarding or its nav chrome: without
        // this, scanning a v4 app from inside a v3 session keeps the v3
        // top/bottom bars overlaid on the v4 app (and requests forwarding to
        // the old server).
        JumpWebViewSession.stop()
        // NativeUIState removed in mobile Gen-B chrome deletion — no-op
        this.host = host
        this.port = port
        if (activity != null) activityRef = WeakReference(activity)

        JumpElementRuntime.beginSession()

        client = OkHttpClient.Builder()
            .readTimeout(0, TimeUnit.MILLISECONDS) // No timeout for WebSocket
            .pingInterval(30, TimeUnit.SECONDS)
            .build()

        // Fetch server info to get the WebSocket port, then connect.
        fetchWsPortAndConnect()
    }

    fun disconnect() {
        isListening = false
        reconnectAttempt = 0
        runloopCall?.cancel()
        runloopCall = null
        webSocket?.close(1000, "Client disconnecting")
        webSocket = null
        client?.dispatcher?.executorService?.shutdown()
        client = null
        isConnected = false
    }

    /**
     * Escape hatch — tear down the live remote session and return to the
     * local Jump home. Fired by [EscapeHatchGesture]'s 3-finger swipe-right;
     * mirrors iOS `JumpBridgeRelay.exitToJump()`. No-op when nothing is
     * connected.
     */
    fun exitToJump() {
        val elementLive = JumpElementRuntime.isActive
        val webviewLive = JumpWebViewSession.isActive
        if (!elementLive && !webviewLive && !isConnected) return

        Log.i(TAG, "Escape hatch — exiting remote app back to Jump")

        // Stop forwarding first so any in-flight WebView request falls back
        // to the local runtime instead of a dead dev server.
        JumpWebViewSession.stop()
        if (elementLive) {
            JumpElementRuntime.endSession() // clears NativeUIBridge tree + isActive
        }
        disconnect()

        mainHandler.post {
            if (webviewLive && !elementLive) {
                // The served app's nav chrome (top bar / bottom nav / side
                // nav / FAB) arrived via its response headers into
                // NativeUIState, which the Scaffold renders around BOTH
                // branches — without this it stays overlaid on the Jump home.
                // NativeUIState removed in mobile Gen-B chrome deletion — no-op
                // WebView exit: the local home tree is still in the bridge
                // (webview mode never forks local publishes away), so
                // flipping isActive shows it immediately (mirrors iOS).
                NativeUIBridge.isActive.value = true
            }
            // Wake the LOCAL Jump home runloop parked in wait_event with a
            // benign native event: the runloop re-renders Home and its publish
            // restores the tree (the "3-finger swipe → white screen" fix);
            // the __jumpResume listener also resyncs the server list.
            NativeElementBridge.sendNativeEvent("__jumpResume", "{}")
        }
    }

    private fun fetchWsPortAndConnect() {
        val infoUrl = "http://$host:$port/jump/info"
        val request = Request.Builder()
            .url(infoUrl)
            .addHeader("Accept", "application/json")
            .build()

        val infoClient = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .build()

        infoClient.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: java.io.IOException) {
                Log.w(TAG, "Failed to fetch server info, using default port: ${e.message}")
                wsPort = port
                openWebSocket()
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    // v4 servers ALWAYS declare `ui` in /jump/info; a server
                    // that omits it predates the field — i.e. a v3-era
                    // server, whose apps are WebView apps (Blade / Livewire /
                    // Inertia over HTTP). Defaulting to native-ui would leave
                    // the client waiting forever for Element.* frames a v3
                    // server never sends.
                    var ui = "webview"
                    wsPort = try {
                        val body = it.body?.string()
                        if (body != null) {
                            val json = JSONObject(body)
                            ui = json.optString("ui", "webview").ifEmpty { "webview" }
                            json.optString("ws_port", port)
                        } else port
                    } catch (e: Exception) {
                        port
                    }
                    Log.i(TAG, "WebSocket port: $wsPort, ui: $ui")

                    // WebView app: no Element.* frames to stream. Render it by
                    // forwarding its HTTP responses through the shell's
                    // WebView — start the session BEFORE the WS opens so
                    // onOpen skips driveRemoteApp, then do the commit-gated
                    // swap (Jump home stays visible until the forwarded
                    // page's first commit). The WS stays open purely as the
                    // device bridge (Camera, Biometrics, …).
                    if (ui == "webview") {
                        Log.i(TAG, "Remote app is a WebView app — rendering via HTTP forward")
                        JumpWebViewSession.start(host, port)
                        mainHandler.post {
                            (activityRef?.get() as? MainActivity)?.jumpWebViewSwap("/")
                                ?: Log.e(TAG, "No MainActivity for jumpWebViewSwap — WebView app cannot render")
                        }
                    }

                    openWebSocket()
                }
            }
        })
    }

    private fun openWebSocket() {
        val connectPort = wsPort.ifEmpty { port }
        val url = "ws://$host:$connectPort"
        Log.i(TAG, "Connecting WebSocket to $url")

        val request = Request.Builder().url(url).build()
        isListening = true

        webSocket = client?.newWebSocket(request, object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                Log.i(TAG, "WebSocket connected")
                isConnected = true
                reconnectAttempt = 0  // healthy connection — reset backoff
                mainHandler.post { onConnected?.invoke() }
                // Native-ui: now that the device WS is registered on the
                // bridge, start the remote runloop so the server begins
                // publishing Element.* frames. WebView mode: the WebView
                // drives requests itself via the forward — the WS is only the
                // device bridge, so skip the runloop (mirrors iOS).
                if (!JumpWebViewSession.isActive) {
                    driveRemoteApp()
                }
            }

            override fun onMessage(webSocket: WebSocket, text: String) {
                handleMessage(text)
            }

            override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
                Log.i(TAG, "WebSocket closing: $code $reason")
                isConnected = false
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
                Log.i(TAG, "WebSocket closed: $code $reason")
                isConnected = false
                scheduleReconnect()
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                Log.e(TAG, "WebSocket error: ${t.message}")
                isConnected = false
                scheduleReconnect()
            }
        })
    }

    // ─────────────────────────────────────────────────────────────────────
    // Drive the remote runloop
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Kick the remote runloop. On the dev server, the native runloop that
     * produces `Element.*` frames only starts when the app's `GET /` is
     * requested (the real Jump client's WebView does this). We fire a
     * held-open GET so Laravel runs its native route; the frames themselves
     * stream back over the WebSocket, not this response body.
     */
    private fun driveRemoteApp() {
        if (runloopCall != null) return
        val c = runloopClient ?: OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(0, TimeUnit.MILLISECONDS)     // held open for the screen's lifetime
            .callTimeout(0, TimeUnit.MILLISECONDS)
            .build()
            .also { runloopClient = it }

        val request = Request.Builder()
            .url("http://$host:$port/")
            .addHeader("X-NativePHP-Jump", "NativePHP-Jump-Client")
            .build()

        Log.i(TAG, "Driving remote runloop: GET http://$host:$port/")
        runloopCall = c.newCall(request).also { call ->
            call.enqueue(object : Callback {
                override fun onFailure(call: Call, e: java.io.IOException) {
                    Log.i(TAG, "runloop GET ended: ${e.message}")
                    runloopCall = null
                }

                override fun onResponse(call: Call, response: Response) {
                    response.close()
                    runloopCall = null
                }
            })
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Message Handling
    // ─────────────────────────────────────────────────────────────────────

    private fun handleMessage(text: String) {
        try {
            val json = JSONObject(text)
            when (json.optString("type", "")) {
                "bridge_call" -> handleBridgeCall(json)
                "event" -> handleEvent(json)
                "reload" -> {
                    Log.i(TAG, "Live reload triggered")
                    mainHandler.post {
                        if (JumpElementRuntime.isActive) {
                            pendingHotReloadReExec = true
                            JumpElementRuntime.enqueueEvent(JSONObject().put("type", EventType.HOT_RELOAD))
                        } else if (JumpWebViewSession.isActive) {
                            // Forwarded WebView session: reload the page so the
                            // dev server's file change shows up.
                            (activityRef?.get() as? MainActivity)?.ensureWebRenderer()?.webView?.reload()
                        } else {
                            onReload?.invoke()
                        }
                    }
                }
                "ping" -> sendPong()
                else -> Log.d(TAG, "Unknown message type: ${json.optString("type", "")}")
            }
        } catch (e: Exception) {
            Log.w(TAG, "Invalid message received: ${e.message}")
        }
    }

    private fun handleBridgeCall(json: JSONObject) {
        val requestId = json.optString("id", "")
        val method = json.optString("method", "")
        if (requestId.isEmpty() || method.isEmpty()) {
            Log.w(TAG, "Bridge call missing id or method")
            return
        }

        // Element.* drive the native element renderer — handled off the main
        // thread (event delivery + publish must never be gated on rendering).
        if (method.startsWith("Element.")) {
            handleElementCall(method, requestId, json.optJSONObject("params"))
            return
        }

        // Convert params JSONObject to Map for BridgeFunction.execute.
        val paramsJson = json.optJSONObject("params")
        val params = mutableMapOf<String, Any>()
        if (paramsJson != null) {
            for (key in paramsJson.keys()) {
                params[key] = paramsJson.get(key)
            }
        }

        // Execute on main thread since many bridge functions need Activity context.
        mainHandler.post {
            val function = BridgeFunctionRegistry.shared.get(method)
            if (function == null) {
                sendResponse(requestId, null, "Function '$method' not found")
                return@post
            }
            try {
                val result = function.execute(params)
                sendResponse(requestId, result, null)
            } catch (e: Exception) {
                sendResponse(requestId, null, e.message ?: "Execution failed")
            }
        }
    }

    private fun handleEvent(json: JSONObject) {
        // WebView-side JS events. The native-render path doesn't need this;
        // no-op for now (mirrors iOS).
        Log.d(TAG, "Ignoring WebView 'event' message (no dispatcher in this shell)")
    }

    private fun handleElementCall(method: String, requestId: String, params: JSONObject?) {
        when (method) {
            "Element.Init" -> {
                JumpElementRuntime.initialize()
                sendResponse(requestId, mapOf("status" to "ok"), null)
            }
            "Element.Publish" -> {
                if (params != null) JumpElementRuntime.publish(params)
                sendResponse(requestId, mapOf("status" to "ok"), null)
            }
            "Element.WaitEvent" -> {
                val timeoutMs = (params?.opt("timeout") as? Number)?.toLong() ?: -1L
                // Release any currently-blocked waiter so the single worker
                // thread frees up before this one runs.
                JumpElementRuntime.supersedeWaiter()
                waitExecutor.execute {
                    val event = JumpElementRuntime.waitEvent(timeoutMs)
                    sendElementResult(requestId, event)
                }
            }
            "Element.Reset" -> {
                JumpElementRuntime.reset()
                sendResponse(requestId, mapOf("status" to "ok"), null)
            }
            "Element.Shutdown" -> {
                JumpElementRuntime.shutdown()
                sendResponse(requestId, mapOf("status" to "ok"), null)
                // Tail end of a native hot reload — the runloop has exited;
                // re-drive GET / ourselves so the server re-executes the app
                // with the changed code. The old runloop GET may not have
                // completed yet, so clear it or driveRemoteApp() no-ops.
                if (pendingHotReloadReExec) {
                    pendingHotReloadReExec = false
                    runloopCall?.cancel()
                    runloopCall = null
                    driveRemoteApp()
                }
            }
            else -> sendResponse(requestId, null, "Unknown element method: $method")
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sending
    // ─────────────────────────────────────────────────────────────────────

    /** Send a bridge_response whose `result` is the raw event object (or {}). */
    private fun sendElementResult(requestId: String, resultJson: JSONObject?) {
        val response = JSONObject().apply {
            put("type", "bridge_response")
            put("id", requestId)
            put("result", resultJson ?: JSONObject())
        }
        webSocket?.send(response.toString())
    }

    private fun sendResponse(requestId: String, result: Map<String, Any>?, error: String?) {
        val response = JSONObject().apply {
            put("type", "bridge_response")
            put("id", requestId)
            if (error != null) {
                put("error", error)
            } else {
                put("result", JSONObject(result ?: emptyMap<String, Any>()))
            }
        }
        webSocket?.send(response.toString())
    }

    private fun sendPong() {
        webSocket?.send(JSONObject().put("type", "pong").toString())
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reconnection
    // ─────────────────────────────────────────────────────────────────────

    private fun scheduleReconnect() {
        if (!isListening) return

        // Give up after a few quick attempts (~3s): the dev server is gone.
        // Signal the screen to return home rather than sit on a dead overlay.
        if (reconnectAttempt >= maxReconnectAttempts) {
            Log.i(TAG, "Reconnect abandoned after $reconnectAttempt attempts — dev server gone")
            isListening = false
            mainHandler.post {
                // Take the escape-hatch path: it tears down the dead session
                // AND wakes the parked local home runloop (__jumpResume) so
                // Home republishes. A bare endSession() clears the tree with
                // nothing behind it — the "killed server → white screen" bug.
                exitToJump()
                onSessionEnded?.invoke()
            }
            return
        }
        reconnectAttempt += 1
        Log.i(TAG, "Reconnecting in 1s (attempt $reconnectAttempt/$maxReconnectAttempts)...")
        mainHandler.postDelayed({
            if (isListening) openWebSocket()
        }, 1000)
    }
}
