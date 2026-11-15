import Foundation

// MARK: - JumpClient Function Namespace

/// Functions for running a Jump session against a `native:jump` dev server.
/// Namespace: "JumpClient.*"
enum JumpClientFunctions {

    // MARK: - JumpClient.Connect

    /// Connect to a dev server by host + port. Drives `JumpBridgeRelay`, which
    /// opens the WebSocket to the dev server and streams its native UI into
    /// `NativeUIBridge.shared` (rendered in place by the shell's existing
    /// renderers).
    class Connect: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let host = parameters["host"] as? String,
                  let port = parameters["port"] as? String else {
                print("❌ JumpClient.Connect missing host/port: \(parameters)")
                return [:]
            }
            print("🔌 JumpClient.Connect → \(host):\(port)")
            DispatchQueue.main.async {
                JumpEscapeHatchGesture.install()
                JumpBridgeRelay.shared.connect(host: host, port: port)
            }
            return [:]
        }
    }
}
