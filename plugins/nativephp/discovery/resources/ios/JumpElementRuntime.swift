import Combine
import Foundation
import os.log

/// Remote native-UI runtime for Jump "connect to a dev server" mode.
///
/// Ported from the Jump client's `JumpElementBridge`, with one deliberate
/// change: instead of owning its own `@Published currentTree`, it writes the
/// parsed tree into the shell's existing `NativeUIBridge.shared` — the same
/// sink the embedded (shared-memory) path feeds and that `ContentView` already
/// renders. So remote frames render with zero renderer/ContentView changes.
///
/// `isActive` here means "a remote session is live" — the shell's
/// `NativeElementBridge.writeEvent` checks it to route taps to this runtime's
/// WebSocket-drained event queue instead of embedded PHP shared memory.
final class JumpElementRuntime: ObservableObject, @unchecked Sendable {
    static let shared = JumpElementRuntime()

    private let logger = Logger(subsystem: "com.nativephp.discovery", category: "ElementRuntime")

    /// True while a remote session is live. Read by the shell's
    /// `NativeElementBridge.writeEvent` to fork the event transport.
    private(set) var isActive: Bool = false

    // MARK: - Event Queue (NSCondition + waiter generation — see waitEvent)

    private var eventQueue: [[String: Any]] = []
    private let eventCondition = NSCondition()
    private var waiterGeneration = 0

    // Publish coalescing — see `publish(json:)`.
    private let publishLock = NSLock()
    private var pendingPublishJson: [String: Any]?
    private var renderScheduled = false

    /// Set while exiting a session so a late in-flight publish can't resurrect
    /// the overlay. Reset by initialize() when a fresh session begins.
    private var suppressed = false

    /// Last applied (diffed) tree, and the last tree per native-chrome URI —
    /// diff baselines so unchanged subtrees keep their refs across publishes and
    /// SwiftUI's identity check short-circuits (no full rebuild = no stutter).
    private var previousTree: NativeUITree?
    private var nativeChromePrevTrees: [String: NativeUITree] = [:]

    private init() {}

    // MARK: - Element lifecycle

    func initialize() {
        logger.info("Element.Init")
        DispatchQueue.main.async {
            registerPluginRenderers()  // shell's renderer registration (idempotent, main-thread)
            self.suppressed = false
            self.isActive = true
        }
    }

    /// Tear down the current session's UI and stop routing events remotely.
    ///
    /// `keepingLastFrame` stops the session WITHOUT clearing the shell's tree,
    /// leaving the remote app's final frame on screen as something for Jump's
    /// home to animate in over (see `JumpBridgeRelay.exitToJump`). The frame is
    /// inert either way — `suppressed`/`isActive` are already false, so no
    /// further remote publishes land and taps no longer route to the remote
    /// queue. Callers that keep the frame MUST get a local publish on screen
    /// afterwards (waking home does this), or the dead frame just sits there.
    func endSession(keepingLastFrame: Bool = false) {
        DispatchQueue.main.async {
            self.suppressed = true
            self.isActive = false
            if !keepingLastFrame {
                NativeUIBridge.shared.isActive = false
                NativeUIBridge.shared.currentTree = nil
            }
        }
        reset()
    }

    func beginSession() {
        DispatchQueue.main.async {
            self.suppressed = false
            self.isActive = false
            NativeUIBridge.shared.navigationPending = false
            NativeUIBridge.shared.pendingTransition = nil
        }
        reset()
    }

    // MARK: - Publish (coalesced, throttled to ~60fps)

    nonisolated func publish(json: [String: Any]) {
        publishLock.lock()
        pendingPublishJson = json
        let needSchedule = !renderScheduled
        if needSchedule { renderScheduled = true }
        publishLock.unlock()

        guard needSchedule else { return }
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.016) { [weak self] in
            self?.applyPendingPublish()
        }
    }

    private func applyPendingPublish() {
        publishLock.lock()
        let json = pendingPublishJson
        pendingPublishJson = nil
        renderScheduled = false
        publishLock.unlock()

        guard let json else { return }
        guard !suppressed else { return }
        let tree = parseTree(json)
        self.isActive = true  // remote session flag (gates the event fork)

        // Mirror the shell's embedded NativeElementBridge apply logic so
        // transitions + view reuse behave identically on the remote path.
        let ui = NativeUIBridge.shared
        let prevRootType = ui.currentTree?.root.type
        let newRootType = tree.root.type
        let isNav = ui.navigationPending
        if isNav { ui.navigationPending = false }

        // A push/pop within the SAME native chrome (NavigationStack / TabView)
        // animates itself — a custom screenKey bump on top of that double-
        // animates ("appears then transitions"). Only bump for plain-root swaps.
        let nativeChromeContinuation =
            (prevRootType == "native_root_stack" && newRootType == "native_root_stack") ||
            (prevRootType == "native_root_tabs" && newRootType == "native_root_tabs")

        // Diff against the right baseline so unchanged subtrees keep their refs
        // (NodeView's === short-circuit → SwiftUI skips rebuilding them). No
        // diff on cross-layout nav / first publish.
        let finalTree: NativeUITree
        let newUri = tree.root.props.getString("current_uri", default: "")
        if !isNav, let prev = previousTree {
            finalTree = NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: Self.diffNode(old: prev.root, new: tree.root))
        } else if isNav, nativeChromeContinuation, !newUri.isEmpty, let prevAtUri = nativeChromePrevTrees[newUri] {
            finalTree = NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: Self.diffNode(old: prevAtUri.root, new: tree.root))
        } else if isNav, nativeChromeContinuation, let prev = previousTree {
            finalTree = NativeUITree(version: tree.version, callbackCount: tree.callbackCount, root: Self.diffNode(old: prev.root, new: tree.root))
        } else {
            finalTree = tree
        }
        previousTree = finalTree
        if newRootType == "native_root_stack" || newRootType == "native_root_tabs" {
            let uri = finalTree.root.props.getString("current_uri", default: "")
            if !uri.isEmpty { nativeChromePrevTrees[uri] = finalTree }
        }

        let wasActive = ui.isActive
        ui.isActive = true

        // Custom (non-chrome) nav while already active: defer the screenKey
        // bump + tree swap one runloop so the OUTGOING screen commits the
        // newly-staged pendingTransition for its exit BEFORE the swap — else
        // SwiftUI removes it with the previous nav's transition (the stutter).
        if isNav && !nativeChromeContinuation && wasActive {
            DispatchQueue.main.async {
                ui.screenKey += 1
                ui.currentTree = finalTree
                if ui.isReloading { ui.isReloading = false }
            }
        } else {
            if isNav && !nativeChromeContinuation { ui.screenKey += 1 }
            ui.currentTree = finalTree
            if ui.isReloading { ui.isReloading = false }
        }
    }

    /// Reuse unchanged subtrees by reference (verbatim from the shell's embedded
    /// NativeElementBridge) so SwiftUI's identity short-circuit fires.
    private static func diffNode(old: NativeUINode, new: NativeUINode) -> NativeUINode {
        guard old.id == new.id, old.type == new.type, old.children.count == new.children.count else {
            return new
        }

        var allChildrenReused = true
        let diffedChildren: [NativeUINode]
        if new.children.isEmpty {
            diffedChildren = new.children
        } else {
            var list: [NativeUINode] = []
            list.reserveCapacity(new.children.count)
            for i in new.children.indices {
                let diffed = diffNode(old: old.children[i], new: new.children[i])
                if diffed !== old.children[i] { allChildrenReused = false }
                list.append(diffed)
            }
            diffedChildren = list
        }

        let fieldsMatch = old.layout == new.layout &&
            old.style == new.style &&
            old.onPress == new.onPress &&
            old.onLongPress == new.onLongPress &&
            old.props == new.props

        if fieldsMatch && allChildrenReused {
            return old
        }
        if fieldsMatch {
            return old.copy(children: diffedChildren)
        }
        return new.copy(children: diffedChildren)
    }

    // MARK: - Event drain (Element.WaitEvent)

    nonisolated func waitEvent(timeoutMs: Int) -> [String: Any]? {
        eventCondition.lock()

        // Supersede any older waiter so at most one WaitEvent blocks a GCD
        // thread at a time (PHP drives one runloop; abandoned waiters must
        // release, not leak the pool).
        waiterGeneration &+= 1
        let myGeneration = waiterGeneration
        eventCondition.broadcast()

        let deadline = timeoutMs < 0
            ? Date.distantFuture
            : Date(timeIntervalSinceNow: Double(timeoutMs) / 1000.0)

        while eventQueue.isEmpty && myGeneration == waiterGeneration {
            if !eventCondition.wait(until: deadline) {
                eventCondition.unlock()
                return nil  // timeout
            }
        }

        if myGeneration != waiterGeneration {
            eventCondition.unlock()
            return nil  // superseded
        }

        let event = eventQueue.isEmpty ? nil : eventQueue.removeFirst()
        eventCondition.unlock()
        return event
    }

    func reset() {
        previousTree = nil
        nativeChromePrevTrees.removeAll()
        eventCondition.lock()
        eventQueue.removeAll()
        waiterGeneration &+= 1
        eventCondition.broadcast()
        eventCondition.unlock()
    }

    func shutdown() {
        // Keep the current UI visible until the next publish replaces it
        // (avoids a blank flash during hot reload). Just drain + release waiters.
        eventCondition.lock()
        eventQueue.removeAll()
        waiterGeneration &+= 1
        eventCondition.broadcast()
        eventCondition.unlock()
    }

    // MARK: - Event enqueue

    private static let coalescingTypes: Set<Int> = [
        EventType.sliderChange,
        EventType.scroll,
        EventType.textChange,
    ]

    func enqueueEvent(_ event: [String: Any]) {
        eventCondition.lock()
        if let type = event["type"] as? Int,
           Self.coalescingTypes.contains(type),
           let nodeId = event["node_id"] as? Int {
            eventQueue.removeAll { ($0["type"] as? Int) == type && ($0["node_id"] as? Int) == nodeId }
        }
        eventQueue.append(event)
        eventCondition.broadcast()
        eventCondition.unlock()
    }

    /// Decode a raw event (as produced by the shell's `NativeElementBridge`
    /// send* buffer layout) into the JSON event dict the dev server expects,
    /// then enqueue it. This is the single seam the shell's `writeEvent`
    /// forks into when a remote session is active.
    func enqueueRawEvent(type: Int, callbackId: Int, nodeId: Int, data: Data?) {
        var event: [String: Any] = [
            "type": type,
            "callback_id": callbackId,
            "node_id": nodeId,
        ]
        let d = data ?? Data()
        switch type {
        case EventType.textChange, EventType.submit,
             EventType.radioChange, EventType.selectChange:
            event["text"] = decodeLenPrefixedString(d, at: 0)
            if type == EventType.radioChange || type == EventType.selectChange {
                event["value"] = event["text"]
            }
        case EventType.toggleChange, EventType.checkboxChange:
            event["value"] = (d.first ?? 0) != 0
        case EventType.sliderChange:
            event["value"] = decodeFloat(d, at: 0)
        case EventType.tabChange:
            event["index"] = decodeU16(d, at: 0)
        case EventType.native:
            // two length-prefixed strings: event name, payload JSON
            let name = decodeLenPrefixedString(d, at: 0)
            let nameLen = 4 + (name.utf8.count)
            let payloadJson = decodeLenPrefixedString(d, at: nameLen)
            event["event"] = name
            if let pd = payloadJson.data(using: .utf8),
               let parsed = (try? JSONSerialization.jsonObject(with: pd)) as? [String: Any] {
                event["payload"] = parsed
            } else {
                event["payload"] = [:]
            }
        default:
            break  // press / longPress / systemBack / sheetDismiss / hotReload: no data
        }
        enqueueEvent(event)
    }

    private func decodeLenPrefixedString(_ d: Data, at offset: Int) -> String {
        guard d.count >= offset + 4 else { return "" }
        let len = Int(decodeU32(d, at: offset))
        let start = offset + 4
        guard len > 0, d.count >= start + len else { return "" }
        return String(decoding: d[start..<start + len], as: UTF8.self)
    }

    private func decodeU16(_ d: Data, at o: Int) -> Int {
        guard d.count >= o + 2 else { return 0 }
        return Int(UInt16(d[o]) | (UInt16(d[o + 1]) << 8))
    }

    private func decodeU32(_ d: Data, at o: Int) -> UInt32 {
        guard d.count >= o + 4 else { return 0 }
        return UInt32(d[o]) | (UInt32(d[o + 1]) << 8) | (UInt32(d[o + 2]) << 16) | (UInt32(d[o + 3]) << 24)
    }

    private func decodeFloat(_ d: Data, at o: Int) -> Float {
        Float(bitPattern: decodeU32(d, at: o))
    }

    // MARK: - Native-event helper (matches NativeElementBridge.sendNativeEvent)

    static func sendNativeEvent(eventName: String, payloadJson: String) {
        var payload: [String: Any] = [:]
        if let data = payloadJson.data(using: .utf8),
           let parsed = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] {
            payload = parsed
        }
        shared.enqueueEvent([
            "type": EventType.native,
            "callback_id": 0,
            "node_id": 0,
            "event": eventName,
            "payload": payload,
        ])
    }

    // MARK: - JSON Tree Parser (verbatim from Jump — node model is identical)

    func parseTree(_ json: [String: Any]) -> NativeUITree {
        let root = parseNode(json)
        return NativeUITree(version: 1, callbackCount: 0, root: root)
    }

    private func parseNode(_ json: [String: Any]) -> NativeUINode {
        let id = (json["id"] as? Int) ?? 0
        let type = (json["type"] as? String) ?? "column"

        let layout: NodeLayout
        if let layoutDict = json["layout"] as? [String: Any] {
            layout = parseLayout(layoutDict)
        } else {
            layout = defaultLayout
        }

        let style: NodeStyle?
        if let styleDict = json["style"] as? [String: Any] {
            style = parseStyle(styleDict)
        } else {
            style = nil
        }

        let props: GenericProps
        if let propsDict = json["props"] as? [String: Any] {
            props = GenericProps(propsDict)
        } else {
            props = GenericProps()
        }

        let onPress = (json["on_press"] as? Int) ?? 0
        let onLongPress = (json["on_long_press"] as? Int) ?? 0

        var children: [NativeUINode] = []
        if let childrenArray = json["children"] as? [[String: Any]] {
            children = childrenArray.map { parseNode($0) }
        }

        return NativeUINode(
            id: id, type: type, layout: layout, style: style,
            props: props, onPress: onPress, onLongPress: onLongPress, children: children
        )
    }

    private func parseLayout(_ d: [String: Any]) -> NodeLayout {
        let (width, widthMode) = parseSizeDimension(d["width"])
        let (height, heightMode) = parseSizeDimension(d["height"])

        let (pT, pR, pB, pL) = parseEdgeInsets(d["padding"])
        let paddingTop = floatOrFirst(d, "paddingTop", "padding_top", fallback: pT)
        let paddingRight = floatOrFirst(d, "paddingRight", "padding_right", fallback: pR)
        let paddingBottom = floatOrFirst(d, "paddingBottom", "padding_bottom", fallback: pB)
        let paddingLeft = floatOrFirst(d, "paddingLeft", "padding_left", fallback: pL)

        let (mT, mR, mB, mL) = parseEdgeInsets(d["margin"])
        let marginTop = floatOrFirst(d, "marginTop", "margin_top", fallback: mT)
        let marginRight = floatOrFirst(d, "marginRight", "margin_right", fallback: mR)
        let marginBottom = floatOrFirst(d, "marginBottom", "margin_bottom", fallback: mB)
        let marginLeft = floatOrFirst(d, "marginLeft", "margin_left", fallback: mL)

        let (posT, posR, posB, posL) = parseEdgeInsets(d["position"])
        let positionTop = floatOrFirst(d, "top", "position_top", fallback: posT)
        let positionRight = floatOrFirst(d, "right", "position_right", fallback: posR)
        let positionBottom = floatOrFirst(d, "bottom", "position_bottom", fallback: posB)
        let positionLeft = floatOrFirst(d, "left", "position_left", fallback: posL)

        return NodeLayout(
            width: width, widthMode: widthMode, height: height, heightMode: heightMode,
            paddingTop: paddingTop, paddingRight: paddingRight, paddingBottom: paddingBottom, paddingLeft: paddingLeft,
            marginTop: marginTop, marginRight: marginRight, marginBottom: marginBottom, marginLeft: marginLeft,
            flexGrow: floatAny(d, "flex_grow", "flexGrow"),
            flexShrink: floatAny(d, "flex_shrink", "flexShrink"),
            alignSelf: intAny(d, "align_self", "alignSelf"),
            alignItems: intAny(d, "align_items", "alignItems"),
            justifyContent: intAny(d, "justify_content", "justifyContent"),
            gap: floatVal(d, "gap"),
            safeArea: intAny(d, "safe_area", "safeArea"),
            minWidth: floatAny(d, "min_width", "minWidth"),
            minHeight: floatAny(d, "min_height", "minHeight"),
            maxWidth: floatAny(d, "max_width", "maxWidth"),
            maxHeight: floatAny(d, "max_height", "maxHeight"),
            flexBasis: floatAny(d, "flex_basis", "flexBasis"),
            flexBasisMode: intAny(d, "flex_basis_mode", "flexBasisMode"),
            flexWrap: intAny(d, "flex_wrap", "flexWrap"),
            flexDirection: intAny(d, "flex_direction", "flexDirection"),
            positionType: intAny(d, "position_type", "positionType"),
            positionTop: positionTop, positionRight: positionRight,
            positionBottom: positionBottom, positionLeft: positionLeft,
            display: intVal(d, "display"),
            overflow: intVal(d, "overflow"),
            alignContent: intAny(d, "align_content", "alignContent"),
            direction: intVal(d, "direction"),
            aspectRatio: floatAny(d, "aspect_ratio", "aspectRatio"),
            rowGap: floatAny(d, "row_gap", "rowGap")
        )
    }

    private func parseStyle(_ d: [String: Any]) -> NodeStyle {
        NodeStyle(
            bgColor: colorVal(d, "bg_color"),
            borderRadius: floatVal(d, "border_radius"),
            borderWidth: floatVal(d, "border_width"),
            borderColor: colorVal(d, "border_color"),
            opacity: d["opacity"] != nil ? floatVal(d, "opacity") : 1.0,
            elevation: floatVal(d, "elevation")
        )
    }

    private let defaultLayout = NodeLayout(
        width: 0, widthMode: 0, height: 0, heightMode: 0,
        paddingTop: 0, paddingRight: 0, paddingBottom: 0, paddingLeft: 0,
        marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0,
        flexGrow: 0, flexShrink: 0, alignSelf: 0, alignItems: 0, justifyContent: 0,
        gap: 0, safeArea: 0,
        minWidth: 0, minHeight: 0, maxWidth: 0, maxHeight: 0,
        flexBasis: 0, flexBasisMode: 0, flexWrap: 0, flexDirection: 0,
        positionType: 0, positionTop: 0, positionRight: 0, positionBottom: 0, positionLeft: 0,
        display: 0, overflow: 0, alignContent: 0, direction: 0, aspectRatio: 0, rowGap: 0
    )

    private func parseSizeDimension(_ value: Any?) -> (Float, Int) {
        guard let value else { return (0, SizeMode.wrap) }
        if let str = value as? String {
            switch str {
            case "fill": return (0, SizeMode.fill)
            case "wrap": return (0, SizeMode.wrap)
            default: return (0, SizeMode.wrap)
            }
        }
        if let n = value as? NSNumber {
            let f = n.floatValue
            return f > 0 ? (f, SizeMode.fixed) : (0, SizeMode.wrap)
        }
        return (0, SizeMode.wrap)
    }

    private func parseEdgeInsets(_ value: Any?) -> (Float, Float, Float, Float) {
        guard let value else { return (0, 0, 0, 0) }
        if let n = value as? NSNumber {
            let f = n.floatValue
            return (f, f, f, f)
        }
        if let arr = value as? [Any] {
            let floats = arr.map { ($0 as? NSNumber)?.floatValue ?? 0 }
            switch floats.count {
            case 1: return (floats[0], floats[0], floats[0], floats[0])
            case 2: return (floats[0], floats[1], floats[0], floats[1])
            case 3: return (floats[0], floats[1], floats[2], floats[1])
            case 4: return (floats[0], floats[1], floats[2], floats[3])
            default: return (0, 0, 0, 0)
            }
        }
        return (0, 0, 0, 0)
    }

    private func floatVal(_ d: [String: Any], _ key: String) -> Float {
        if let n = d[key] as? NSNumber { return n.floatValue }
        if let n = d[key] as? Int { return Float(n) }
        if let n = d[key] as? Double { return Float(n) }
        return 0
    }

    private func floatAny(_ d: [String: Any], _ keys: String...) -> Float {
        for key in keys where d[key] != nil { return floatVal(d, key) }
        return 0
    }

    private func intVal(_ d: [String: Any], _ key: String) -> Int {
        if let n = d[key] as? Int { return n }
        if let n = d[key] as? NSNumber { return n.intValue }
        return 0
    }

    private func intAny(_ d: [String: Any], _ keys: String...) -> Int {
        for key in keys where d[key] != nil { return intVal(d, key) }
        return 0
    }

    private func floatOrFirst(_ d: [String: Any], _ keys: String..., fallback: Float) -> Float {
        for key in keys where d[key] != nil { return floatVal(d, key) }
        return fallback
    }

    private func colorVal(_ d: [String: Any], _ key: String) -> Int {
        if let n = d[key] as? Int { return n }
        if let n = d[key] as? NSNumber { return n.intValue }
        if let s = d[key] as? String { return ColorParser.parse(s) }
        return 0
    }
}
