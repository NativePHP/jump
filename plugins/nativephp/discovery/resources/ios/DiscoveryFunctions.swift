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
}
