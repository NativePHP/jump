import Combine
import Foundation
import os.log

/// WebSocket relay for connecting this app to a remote NativePHP dev server
/// (`php artisan native:jump`) and rendering its native UI in place.
///
/// Ported from the Jump client's `JumpBridgeRelay`. It receives bridge calls
/// from the dev machine, executes device APIs locally via the shell's existing
/// `BridgeFunctionRegistry`, and routes `Element.*` frames through
/// `JumpElementRuntime` (which renders into `NativeUIBridge.shared`).
class JumpBridgeRelay: NSObject, ObservableObject {
    static let shared = JumpBridgeRelay()

    private let logger = Logger(subsystem: "com.nativephp.discovery", category: "BridgeRelay")

    @Published private(set) var isConnected = false

    private var webSocketTask: URLSessionWebSocketTask?
    private var session: URLSession?
    private var host: String = ""
    private var port: String = ""
    private var wsPort: String = ""
    private var runloopTask: URLSessionDataTask?
    private var isListening = false
    private var reconnectScheduled = false
    private var reconnectAttempt = 0
    private let maxReconnectAttempts = 3
    private var pendingHotReloadReExec = false

    /// Fired (main thread) when reconnection is abandoned — the dev server is
    /// gone. Default clears the remote session so the UI returns to the app.
    var onSessionEnded: (() -> Void)?
    /// Fired on a live-reload signal for non-native (WebView) content.
    var onReload: (() -> Void)?


    override init() {
        super.init()
        // Escape hatch: a global 3-finger swipe-right (posted by the shell's
        // EscapeHatchGesture) exits any connected demo app back to the Jump home.
        NotificationCenter.default.addObserver(
            forName: NSNotification.Name("JumpEscapeHatch"),
            object: nil,
            queue: .main
        ) { [weak self] _ in
            self?.exitToJump()
        }
    }

    /// Escape hatch — tear down whatever remote session is live and return to the
    /// local Jump home. Works for both a forwarded WebView app (JumpWebViewSession)
    /// and a streamed native-ui app (JumpElementRuntime). No-op when nothing is
    /// connected.
    func exitToJump() {
        let webviewLive = JumpWebViewSession.shared.isActive
        let elementLive = JumpElementRuntime.shared.isActive
        guard webviewLive || elementLive || isConnected else { return }

        logger.info("Escape hatch — exiting remote app back to Jump")

        // Stop forwarding / streaming and drop the WS.
        JumpWebViewSession.shared.stop()
        if elementLive {
            // Keep the remote app's final frame on screen. It's inert (no
            // further remote publishes, taps no longer route remotely), but it
            // gives home's republish something to animate in OVER. Clearing the
            // tree here is what made the exit snap: the remote UI vanished a
            // frame or more before home arrived.
            JumpElementRuntime.shared.endSession(keepingLastFrame: true)
        }
        disconnect()

        DispatchQueue.main.async {
            if !elementLive {
                // WebView exit: the local home tree is still in currentTree (its
                // local publishes were only suppressed while forwarding), so
                // flipping isActive shows it immediately and interactive.
                NativeUIBridge.shared.isActive = true
            } else {
                // Stage the swap BEFORE waking home, so the publish that
                // repaints home is consumed as a navigation: the held remote
                // frame is reclassified as `outgoingScreen` and home gets a
                // fresh screenKey whose insertion transition animates.
                // `slide_from_left` brings home in from the leading edge — the
                // iOS "back" idiom, matching the 3-finger swipe-RIGHT that got
                // us here.
                //
                // Degrades quietly: a remote app whose root sentinel matches
                // home's (tabs → tabs) counts as a native-chrome continuation,
                // which skips the two-layer swap — you get today's instant
                // repaint, not a broken frame.
                NativeUIBridge.shared.setNavigationPending(transition: "slide_from_left")
            }
            // Wake the LOCAL Jump home runloop parked in wait_event. For a
            // native-ui exit this is what repaints home (and, with the
            // transition staged above, what drives the exit animation) — the
            // "3-finger swipe → white screen" fix. For BOTH exit kinds the
            // __jumpResume listener also resyncs the server list and
            // re-pushes Jump's theme (the remote app's Theme.Set clobbered
            // the native theme store). Mirrors Android.
            NativeElementBridge.sendNativeEvent(eventName: "__jumpResume", payloadJson: "{}")
        }
    }
    deinit { disconnect() }

    /// Route device-API result events (Camera.PhotoTaken, Geolocation.*,
    /// Biometrics.*, Scanner.CodeScanned, …) to the remote server over the
    /// element event queue while a Jump session is live, instead of injecting
    /// them into the local WebView. Idempotent; safe to call on every connect.
    private func installLaravelEventFork() {
        LaravelBridge.shared.send = { event, payload in
            if JumpElementRuntime.shared.isActive {
                let dict = payload.reduce(into: [String: Any]()) { $0[$1.key] = $1.value ?? NSNull() }
                let json = (try? JSONSerialization.data(withJSONObject: dict))
                    .flatMap { String(data: $0, encoding: .utf8) } ?? "{}"
                JumpElementRuntime.sendNativeEvent(eventName: event, payloadJson: json)
            } else {
                // Fall back to the CURRENT local channel, read at dispatch
                // time. In a forwarded-WebView session this is the coordinator
                // closure (JS injection into the page — the only road back to
                // the served app for async device results); capturing it at
                // install time went stale on native-direct boots, where the
                // WebView materializes after connect.
                LaravelBridge.shared.localSend(event, payload)
            }
        }
    }

    // MARK: - Connection

    func connect(host: String, port: String) {
        disconnect()
        // A previous webview-forward session may still be live — disconnect()
        // deliberately leaves it alone (only exitToJump stops it), but a NEW
        // session must not inherit its forwarding: PHPSchemeHandler would keep
        // proxying php:// to the OLD dev server. The webview branch below
        // re-starts it with the new host/port when the new app is webview too.
        JumpWebViewSession.shared.stop()
        self.host = host
        self.port = port

        // Ensure the device-API + plugin bridge functions are registered so
        // remote bridge_calls resolve. Idempotent (register overwrites).
        registerBridgeFunctions()
        registerPluginBridgeFunctions()

        JumpElementRuntime.shared.beginSession()
        installLaravelEventFork()

        let config = URLSessionConfiguration.default
        session = URLSession(configuration: config, delegate: self, delegateQueue: OperationQueue())
        fetchWsPortAndConnect()
    }

    private func fetchWsPortAndConnect() {
        guard let infoUrl = URL(string: "http://\(host):\(port)/jump/info") else {
            openWebSocket()
            return
        }
        let task = URLSession.shared.dataTask(with: infoUrl) { [weak self] data, _, _ in
            guard let self = self else { return }
            var wsPort = self.port
            // v4 servers ALWAYS declare `ui` in /jump/info; a server that
            // omits it predates the field — i.e. a v3-era server, whose apps
            // are WebView apps (Blade/Livewire/Inertia over HTTP). Defaulting
            // to native-ui would leave the client waiting forever for
            // Element.* frames a v3 server never sends.
            var ui = "webview"
            if let data = data,
               let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                if let p = json["ws_port"] as? String { wsPort = p }
                if let u = json["ui"] as? String { ui = u }
            }

            // WebView app (Blade / Livewire / Inertia): there are no Element.*
            // frames to stream. Render it by forwarding its HTTP responses
            // through the shell's WebView — flip into WebView mode and load
            // php://127.0.0.1/, which PHPSchemeHandler forwards to host:port.
            // (Device calls over the WS bridge are a follow-up.)
            self.wsPort = wsPort
            if ui == "webview" {
                self.logger.info("Remote app is a WebView app — rendering via HTTP forward")
                // Start the render session synchronously (thread-safe) so it's
                // already active when the WS opens (didOpenWithProtocol reads it).
                JumpWebViewSession.shared.start(host: self.host, port: self.port)
                DispatchQueue.main.async {
                    NativeUIBridge.shared.isActive = false
                    // Native-direct boots never constructed the WKWebView, so
                    // the redirect below would fire into the void (no
                    // coordinator observes it yet). Allow + seed the lazy
                    // WebView with "/" — its first load goes through
                    // PHPSchemeHandler, which forwards to the connected dev
                    // server. When a WebView already exists, the redirect
                    // notification retargets it as before.
                    BootState.shared.allowWebView(loading: "/")
                    NotificationCenter.default.post(
                        name: NSNotification.Name("RedirectToURLNotification"),
                        object: nil,
                        userInfo: ["url": "php://127.0.0.1/"]
                    )
                    self.isConnected = true
                }
                // Open the WS so device bridge_calls (Camera, etc.) reach the
                // phone — this is the device bridge. driveRemoteApp() is skipped
                // in WebView mode; the render session (JumpWebViewSession) is
                // decoupled from this WS's lifecycle, so a WS reconnect/close
                // can't tear down the forwarded WebView.
                self.openWebSocket()
                return
            }

            self.logger.info("WebSocket port: \(wsPort)")
            self.openWebSocket()
        }
        task.resume()
    }

    func disconnect() {
        isListening = false
        reconnectAttempt = 0
        // NOTE: deliberately does NOT stop JumpWebViewSession or restore native-ui.
        // The WebView render session is decoupled from the WS lifecycle — tearing
        // the WS down (including connect()'s own cleanup call) must not kill the
        // forwarded WebView. Leaving WebView mode is explicit: jump://native.
        runloopTask?.cancel()
        runloopTask = nil
        webSocketTask?.cancel(with: .goingAway, reason: nil)
        webSocketTask = nil
        session?.invalidateAndCancel()
        session = nil
        DispatchQueue.main.async { self.isConnected = false }
    }

    private func openWebSocket() {
        if let existing = webSocketTask {
            existing.cancel(with: .goingAway, reason: nil)
            webSocketTask = nil
        }
        let connectPort = wsPort.isEmpty ? port : wsPort
        guard let url = URL(string: "ws://\(host):\(connectPort)") else {
            logger.error("Invalid WebSocket URL")
            return
        }
        logger.info("Connecting WebSocket to \(url.absoluteString)")
        webSocketTask = session?.webSocketTask(with: url)
        webSocketTask?.resume()
        isListening = true
        listenForMessages()
    }

    // MARK: - Message Handling

    private func listenForMessages() {
        guard isListening, let task = webSocketTask else { return }
        task.receive { [weak self] result in
            guard let self = self, self.isListening else { return }
            guard task === self.webSocketTask else { return }
            switch result {
            case .success(let message):
                switch message {
                case .string(let text): self.handleMessage(text)
                case .data(let data):
                    if let text = String(data: data, encoding: .utf8) { self.handleMessage(text) }
                @unknown default: break
                }
                self.listenForMessages()
            case .failure(let error):
                DebugLogger.shared.log("🔌 WS receive error: \(error.localizedDescription)")
                self.logger.error("WebSocket receive error: \(error.localizedDescription)")
                DispatchQueue.main.async { self.isConnected = false }
                self.scheduleReconnect()
            }
        }
    }

    private func handleMessage(_ text: String) {
        guard let data = text.data(using: .utf8),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let type = json["type"] as? String else {
            return
        }
        switch type {
        case "bridge_call": handleBridgeCall(json)
        case "event": handleEvent(json)
        case "reload":
            DispatchQueue.main.async { [weak self] in
                guard let self = self else { return }
                if JumpElementRuntime.shared.isActive {
                    self.pendingHotReloadReExec = true
                    JumpElementRuntime.shared.enqueueEvent(["type": EventType.hotReload])
                } else if JumpWebViewSession.shared.isActive {
                    // Forwarded WebView session: reload the page so the dev
                    // server's file change shows up (onReload is a host hook
                    // nobody wires — same disease as the native re-exec bug).
                    NotificationCenter.default.post(
                        name: NSNotification.Name("ReloadWebViewNotification"),
                        object: nil
                    )
                } else {
                    self.onReload?()
                }
            }
        case "ping": sendPong()
        default: break
        }
    }

    private func handleBridgeCall(_ json: [String: Any]) {
        guard let requestId = json["id"] as? String,
              let method = json["method"] as? String else { return }
        let params = json["params"] as? [String: Any] ?? [:]

        if method.hasPrefix("Element.") {
            handleElementCall(method: method, requestId: requestId, params: params)
            return
        }

        DispatchQueue.main.async { [weak self] in
            guard let self = self else { return }
            guard let function = BridgeFunctionRegistry.shared.get(method) else {
                self.sendResponse(requestId: requestId, result: nil, error: "Function '\(method)' not found")
                return
            }
            do {
                let result = try function.execute(parameters: params)
                self.sendResponse(requestId: requestId, result: result, error: nil)
            } catch {
                self.sendResponse(requestId: requestId, result: nil, error: error.localizedDescription)
            }
        }
    }

    private func handleElementCall(method: String, requestId: String, params: [String: Any]) {
        let runtime = JumpElementRuntime.shared
        switch method {
        case "Element.Init":
            runtime.initialize()
            sendResponse(requestId: requestId, result: ["status": "ok"], error: nil)
        case "Element.Publish":
            runtime.publish(json: params)
            sendResponse(requestId: requestId, result: ["status": "ok"], error: nil)
        case "Element.WaitEvent":
            let timeoutMs = params["timeout"] as? Int ?? -1
            DispatchQueue.global(qos: .userInitiated).async { [weak self] in
                let event = runtime.waitEvent(timeoutMs: timeoutMs)
                self?.sendResponse(requestId: requestId, result: event, error: nil)
            }
        case "Element.Reset":
            runtime.reset()
            sendResponse(requestId: requestId, result: ["status": "ok"], error: nil)
        case "Element.Shutdown":
            runtime.shutdown()
            sendResponse(requestId: requestId, result: ["status": "ok"], error: nil)
            // Tail end of a native hot reload — the runloop has exited;
            // re-drive GET / ourselves so the server re-executes the app with
            // the changed code. The old runloop GET may not have completed
            // yet, so clear it or driveRemoteApp() no-ops.
            if pendingHotReloadReExec {
                pendingHotReloadReExec = false
                runloopTask?.cancel()
                runloopTask = nil
                driveRemoteApp()
            }
        default:
            sendResponse(requestId: requestId, result: nil, error: "Unknown element method: \(method)")
        }
    }

    private func handleEvent(_ json: [String: Any]) {
        // WebView-side JS events. The vanilla shell has no NativeEventDispatcher;
        // the native-render path doesn't need this, so it's a no-op for now.
        logger.debug("Ignoring WebView 'event' message (no dispatcher in this shell)")
    }

    // MARK: - Sending

    private func sendResponse(requestId: String, result: [String: Any]?, error: String?) {
        var response: [String: Any] = ["type": "bridge_response", "id": requestId]
        if let error = error {
            response["error"] = error
        } else {
            response["result"] = result ?? [:]
        }
        guard let data = try? JSONSerialization.data(withJSONObject: response),
              let text = String(data: data, encoding: .utf8) else { return }
        webSocketTask?.send(.string(text)) { [weak self] error in
            if let error = error {
                self?.logger.error("Failed to send response: \(error.localizedDescription)")
            }
        }
    }

    /// Kick the remote runloop. On the dev server, the native runloop that
    /// produces `Element.*` frames only starts when the app's `GET /` is
    /// requested (the real Jump client's WebView does this). We fire a
    /// held-open GET so Laravel runs its native route; the frames themselves
    /// stream back over the WebSocket, not this response body.
    private func driveRemoteApp() {
        guard runloopTask == nil else { return }
        guard let url = URL(string: "http://\(host):\(port)/") else { return }
        var req = URLRequest(url: url)
        req.timeoutInterval = 3600  // held open for the screen's lifetime
        req.setValue("NativePHP-Jump-Client", forHTTPHeaderField: "X-NativePHP-Jump")
        runloopTask = URLSession.shared.dataTask(with: req) { [weak self] _, _, error in
            if let error = error {
                self?.logger.info("runloop GET ended: \(error.localizedDescription)")
            }
            self?.runloopTask = nil
        }
        logger.info("Driving remote runloop: GET http://\(self.host):\(self.port)/")
        runloopTask?.resume()
    }

    private func sendPong() {
        guard let data = try? JSONSerialization.data(withJSONObject: ["type": "pong"]),
              let text = String(data: data, encoding: .utf8) else { return }
        webSocketTask?.send(.string(text)) { _ in }
    }

    // MARK: - Reconnection

    private func scheduleReconnect() {
        guard isListening, !reconnectScheduled else { return }
        if reconnectAttempt >= maxReconnectAttempts {
            logger.info("Reconnect abandoned — dev server gone")
            isListening = false
            DispatchQueue.main.async { [weak self] in
                // Take the escape-hatch path: it tears down the dead session
                // AND wakes the parked local home runloop (__jumpResume) so
                // Home republishes. A bare endSession() clears the tree with
                // nothing behind it — the "killed server → white screen" bug.
                self?.exitToJump()
                self?.onSessionEnded?()
            }
            return
        }
        reconnectScheduled = true
        reconnectAttempt += 1
        DispatchQueue.global().asyncAfter(deadline: .now() + 1.0) { [weak self] in
            guard let self = self else { return }
            self.reconnectScheduled = false
            guard self.isListening else { return }
            self.openWebSocket()
        }
    }
}

// MARK: - URLSessionWebSocketDelegate

extension JumpBridgeRelay: URLSessionWebSocketDelegate {
    func urlSession(_ session: URLSession, webSocketTask: URLSessionWebSocketTask, didOpenWithProtocol protocol: String?) {
        DebugLogger.shared.log("🔌 WS connected ws://\(host):\(wsPort.isEmpty ? port : wsPort) webviewSession=\(JumpWebViewSession.shared.isActive)")
        logger.info("WebSocket connected")
        reconnectAttempt = 0
        DispatchQueue.main.async { self.isConnected = true }
        // Native-ui: now that the device WS is registered on the bridge, start
        // the remote runloop so the server begins publishing Element.* frames.
        // WebView mode: the WebView drives GET / itself via the forward, so we
        // keep the WS open only for device bridge_calls and skip the runloop.
        if !JumpWebViewSession.shared.isActive {
            driveRemoteApp()
        }
    }

    func urlSession(_ session: URLSession, webSocketTask: URLSessionWebSocketTask, didCloseWith closeCode: URLSessionWebSocketTask.CloseCode, reason: Data?) {
        let reasonStr = reason.flatMap { String(data: $0, encoding: .utf8) } ?? ""
        DebugLogger.shared.log("🔌 WS closed code=\(closeCode.rawValue) reason=\(reasonStr) webviewSession=\(JumpWebViewSession.shared.isActive)")
        logger.info("WebSocket disconnected (code: \(closeCode.rawValue))")
        DispatchQueue.main.async { self.isConnected = false }
        scheduleReconnect()
    }
}
