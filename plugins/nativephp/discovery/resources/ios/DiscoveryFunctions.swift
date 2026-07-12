import Foundation

// MARK: - Discovery Function Namespace

/// Functions for browsing the LAN for `_jump._tcp` dev servers.
/// Namespace: "Discovery.*"
enum DiscoveryFunctions {

    // MARK: - Discovery.Start

    /// Start browsing for dev servers. Fires `ServerFound` / `ServerLost`
    /// events as servers appear and disappear.
    class Start: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("🛰️ Discovery.Start")
            DispatchQueue.main.async {
                BonjourServerDiscovery.shared.start()
            }
            return [:]
        }
    }

    // MARK: - Discovery.Stop

    /// Stop browsing and tear down the native browser.
    class Stop: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("🛰️ Discovery.Stop")
            DispatchQueue.main.async {
                BonjourServerDiscovery.shared.stop()
            }
            return [:]
        }
    }

    // MARK: - Discovery.Connect

    /// Connect to a discovered dev server by host + port. Drives the ported
    /// `JumpBridgeRelay`, which opens the WebSocket to the dev server and
    /// streams its native UI into `NativeUIBridge.shared` (rendered in place by
    /// the shell's existing renderers).
    class Connect: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let host = parameters["host"] as? String,
                  let port = parameters["port"] as? String else {
                print("❌ Discovery.Connect missing host/port: \(parameters)")
                return [:]
            }
            print("🔌 Discovery.Connect → \(host):\(port)")
            DispatchQueue.main.async {
                JumpBridgeRelay.shared.connect(host: host, port: port)
            }
            return [:]
        }
    }
}
