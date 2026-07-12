import Foundation
import Network

/// A Jump dev server discovered on the LAN. host+port match what the QR encodes.
struct DiscoveredServer: Identifiable, Equatable {
    let id: String   // "host:port"
    let name: String
    let host: String
    let port: String
}

/// Browses for `_jump._tcp` via Bonjour (Network framework). The dev server
/// advertises host+port+name in TXT records (see JumpCommand::advertiseOnNetwork),
/// so we read everything straight from the browse metadata — no resolve step.
///
/// Unlike the original Jump host-app implementation (which published a
/// SwiftUI `@Published` list), this plugin version DISPATCHES Laravel events
/// (`ServerFound` / `ServerLost`) into PHP as the server set changes, so a
/// NativeComponent can react with `#[OnNative(...)]`.
final class BonjourServerDiscovery {

    static let shared = BonjourServerDiscovery()

    private let serverFoundEvent = "NativePHP\\Discovery\\Events\\ServerFound"
    private let serverLostEvent  = "NativePHP\\Discovery\\Events\\ServerLost"

    private var browser: NWBrowser?
    private var restartScheduled = false

    /// Servers parsed from the browser, BEFORE liveness filtering — re-filtered
    /// whenever probe results change.
    private var rawServers: [DiscoveredServer] = []
    /// The set we've told PHP about, keyed by "host:port". Diffed against the
    /// live set to emit ServerFound / ServerLost deltas exactly once each.
    private var emitted: [String: DiscoveredServer] = [:]
    /// Servers that failed a liveness probe, masked until the given date.
    /// mDNS goodbye packets are multicast-once and iOS misses them readily
    /// (Wi-Fi power save), so a dead server can sit in the cache for its full
    /// record TTL. Probing `/jump/info` keeps the list honest.
    private var deadUntil: [String: Date] = [:]
    private var probeTimer: Timer?

    func start() {
        guard browser == nil else { return }
        launchBrowser()
        startProbeTimer()
    }

    /// Tear down and relaunch the browser (browser wedged / app foreground).
    func restart() {
        browser?.cancel()
        browser = nil
        launchBrowser()
    }

    func stop() {
        browser?.cancel()
        browser = nil
        probeTimer?.invalidate()
        probeTimer = nil
        rawServers = []
        // Tell PHP everything went away so the UI clears.
        for server in emitted.values {
            dispatchLost(server)
        }
        emitted = [:]
        deadUntil = [:]
    }

    private func launchBrowser() {
        let params = NWParameters()
        params.includePeerToPeer = true
        let browser = NWBrowser(
            for: .bonjourWithTXTRecord(type: "_jump._tcp", domain: nil),
            using: params
        )
        self.browser = browser

        // `.failed` (mDNS daemon restart / network change) is terminal and
        // `.waiting` (Local Network permission denied / mid-grant) can persist
        // after the user grants access — both leave an empty list forever
        // unless we relaunch.
        browser.stateUpdateHandler = { [weak self, weak browser] state in
            guard let self, let browser, browser === self.browser else { return }
            switch state {
            case .failed(let error):
                print("[Discovery] browser failed: \(error) — restarting")
                self.scheduleRestart(after: 2)
            case .waiting(let error):
                print("[Discovery] browser waiting: \(error) — will retry")
                self.scheduleRestart(after: 5)
            case .ready:
                print("[Discovery] browser ready")
            default:
                break
            }
        }
        browser.browseResultsChangedHandler = { [weak self] results, _ in
            self?.update(results)
        }
        browser.start(queue: .main)
    }

    private func scheduleRestart(after seconds: TimeInterval) {
        guard !restartScheduled else { return }
        restartScheduled = true
        DispatchQueue.main.asyncAfter(deadline: .now() + seconds) { [weak self] in
            guard let self else { return }
            self.restartScheduled = false
            if self.browser != nil {
                self.restart()
            }
        }
    }

    private func update(_ results: Set<NWBrowser.Result>) {
        var out: [DiscoveredServer] = []
        var seen = Set<String>()
        for result in results {
            guard case let .bonjour(txt) = result.metadata else { continue }
            guard let host = txtValue(txt, "host"), !host.isEmpty,
                  let port = txtValue(txt, "port"), !port.isEmpty else { continue }
            let name = txtValue(txt, "name") ?? serviceName(result.endpoint) ?? "Jump server"
            let key = "\(host):\(port)"
            if seen.insert(key).inserted {
                out.append(DiscoveredServer(id: key, name: name, host: host, port: port))
            }
        }
        rawServers = out.sorted { $0.name < $1.name }
        reconcile()
        probeAll()
    }

    // MARK: - Event diffing

    /// Emit ServerFound / ServerLost deltas so PHP hears about each server
    /// exactly once. The live set = raw servers minus any currently masked by
    /// a failed liveness probe.
    private func reconcile() {
        let now = Date()
        deadUntil = deadUntil.filter { $0.value > now }

        var live: [String: DiscoveredServer] = [:]
        for server in rawServers where deadUntil[server.id] == nil {
            live[server.id] = server
        }

        // New / changed servers.
        for (key, server) in live where emitted[key] != server {
            emitted[key] = server
            dispatchFound(server)
        }
        // Gone servers.
        for (key, server) in emitted where live[key] == nil {
            emitted.removeValue(forKey: key)
            dispatchLost(server)
        }

        print("[Discovery] \(emitted.count) live server(s)")
    }

    // MARK: - Liveness probing

    private func startProbeTimer() {
        probeTimer?.invalidate()
        let timer = Timer(timeInterval: 8, repeats: true) { [weak self] _ in
            self?.probeAll()
        }
        RunLoop.main.add(timer, forMode: .common)
        probeTimer = timer
    }

    /// Probe every advertised server's `/jump/info`. A failure masks the entry
    /// for 30s (re-probed after, so a restarted server reappears); a success
    /// unmasks immediately.
    private func probeAll() {
        for server in rawServers {
            guard let url = URL(string: "http://\(server.host):\(server.port)/jump/info") else { continue }
            var request = URLRequest(url: url)
            request.timeoutInterval = 2

            URLSession.shared.dataTask(with: request) { [weak self] _, response, error in
                let alive = error == nil && (response as? HTTPURLResponse)?.statusCode == 200
                DispatchQueue.main.async {
                    guard let self else { return }
                    if alive {
                        if self.deadUntil.removeValue(forKey: server.id) != nil {
                            self.reconcile()
                        }
                    } else {
                        print("[Discovery] \(server.id) failed liveness probe — hiding")
                        self.deadUntil[server.id] = Date().addingTimeInterval(30)
                        self.reconcile()
                    }
                }
            }.resume()
        }
    }

    // MARK: - Event dispatch

    private func dispatchFound(_ server: DiscoveredServer) {
        LaravelBridge.shared.send?(serverFoundEvent, [
            "host": server.host,
            "port": server.port,
            "name": server.name,
        ])
    }

    private func dispatchLost(_ server: DiscoveredServer) {
        LaravelBridge.shared.send?(serverLostEvent, [
            "host": server.host,
            "port": server.port,
        ])
    }

    private func txtValue(_ txt: NWTXTRecord, _ key: String) -> String? {
        if case let .string(value) = txt.getEntry(for: key) { return value }
        return nil
    }

    private func serviceName(_ endpoint: NWEndpoint) -> String? {
        if case let .service(name, _, _, _) = endpoint { return name }
        return nil
    }
}
