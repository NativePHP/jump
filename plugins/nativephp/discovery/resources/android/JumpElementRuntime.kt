package com.nativephp.discovery

import android.os.Handler
import android.os.Looper
import android.util.Log
import com.nativephp.mobile.bridge.plugins.registerPluginRenderers
import com.nativephp.mobile.ui.nativerender.ColorParser
import com.nativephp.mobile.ui.nativerender.EventType
import com.nativephp.mobile.ui.nativerender.GenericProps
import com.nativephp.mobile.ui.nativerender.NativeUIBridge
import com.nativephp.mobile.ui.nativerender.NativeUINode
import com.nativephp.mobile.ui.nativerender.NativeUITree
import com.nativephp.mobile.ui.nativerender.NodeLayout
import com.nativephp.mobile.ui.nativerender.NodeStyle
import com.nativephp.mobile.ui.nativerender.registerNativeChromeRenderers
import org.json.JSONArray
import org.json.JSONObject
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.util.concurrent.locks.ReentrantLock

/**
 * Remote native-UI runtime for Jump "connect to a dev server" mode (Android).
 *
 * Mirror of the iOS `JumpElementRuntime`. Instead of owning its own tree
 * state, it writes the parsed JSON `Element.*` frames into the shell's
 * existing [NativeUIBridge] — the same sink the embedded (JNI / shared-memory)
 * path feeds and that `MainActivity`'s Compose overlay already renders. So
 * remote frames render with zero renderer / Activity changes.
 *
 * [isActive] here means "a remote session is live" — the shell's
 * `NativeElementBridge.writeEvent` checks it to route taps to this runtime's
 * WebSocket-drained event queue instead of the embedded PHP JNI channel.
 *
 * This is deliberately a SEPARATE object from the shell's embedded
 * `NativeElementBridge`, so the embedded runtime stays intact for the app's
 * own home screen. Combines the Air client's remote `NativeElementBridge`
 * JSON parse + JVM event queue with the iOS runtime's `enqueueRawEvent`
 * decoder (which converts the shell's raw send* buffer layout into the JSON
 * events the dev server expects).
 */
object JumpElementRuntime {

    private const val TAG = "JumpElementRuntime"

    /**
     * True while a remote session is live. Read by the shell's
     * `NativeElementBridge.writeEvent` to fork the event transport.
     * `@JvmStatic @Volatile` so the cross-package fork sees writes promptly.
     */
    @JvmStatic
    @Volatile
    var isActive: Boolean = false
        private set

    private val mainHandler = Handler(Looper.getMainLooper())

    /** Continuous-value events — only the latest per node matters (coalesced). */
    private val coalescingTypes = setOf(
        EventType.SLIDER_CHANGE, EventType.SCROLL, EventType.TEXT_CHANGE
    )

    // ─────────────────────────────────────────────────────────────────────
    // Event queue (renderers/enqueueRawEvent enqueue; relay drains via waitEvent)
    // ─────────────────────────────────────────────────────────────────────

    private val lock = ReentrantLock()
    private val notEmpty = lock.newCondition()
    private val eventQueue = ArrayDeque<JSONObject>()

    /** Bumped on every waitEvent; an older blocked waiter sees it changed and bails. */
    private var waiterGeneration = 0

    /** Set on exit so a late in-flight publish can't re-activate the overlay;
     *  cleared by [beginSession] / [initialize]. */
    @Volatile
    private var suppressed = false

    // ─────────────────────────────────────────────────────────────────────
    // Element lifecycle
    // ─────────────────────────────────────────────────────────────────────

    /** Element.Init — register renderers (idempotent) and mark the session live. */
    fun initialize() {
        Log.i(TAG, "Element.Init")
        mainHandler.post {
            registerNativeChromeRenderers()
            registerPluginRenderers()
            suppressed = false
            isActive = true
        }
    }

    /** Tear down the current session's UI and stop routing events remotely. */
    fun endSession() {
        mainHandler.post {
            suppressed = true
            isActive = false
            NativeUIBridge.isActive.value = false
            NativeUIBridge.currentTree.value = null
        }
        reset()
    }

    /** Start of a session: clear leftover exit-time state so a fresh runtime
     *  always renders even if its Element.Init is delayed. */
    fun beginSession() {
        mainHandler.post {
            suppressed = false
            isActive = false
            NativeUIBridge.navigationPending = false
            NativeUIBridge.pendingTransition.value = null
        }
        reset()
    }

    // ─────────────────────────────────────────────────────────────────────
    // Publish — coalesced + throttled, applied on the main thread (~60fps)
    // ─────────────────────────────────────────────────────────────────────

    private val publishLock = ReentrantLock()
    private var pendingPublish: JSONObject? = null
    private var renderScheduled = false

    /**
     * Element.Publish. Stores only the LATEST tree and schedules one
     * main-thread apply per frame (~16ms); intermediate trees are dropped.
     * Safe to call from any thread.
     */
    fun publish(json: JSONObject) {
        publishLock.lock()
        val needSchedule: Boolean
        try {
            pendingPublish = json
            needSchedule = !renderScheduled
            if (needSchedule) renderScheduled = true
        } finally {
            publishLock.unlock()
        }
        if (needSchedule) {
            mainHandler.postDelayed({ applyPendingPublish() }, 16L)
        }
    }

    private fun applyPendingPublish() {
        val json: JSONObject?
        publishLock.lock()
        try {
            json = pendingPublish
            pendingPublish = null
            renderScheduled = false
        } finally {
            publishLock.unlock()
        }
        if (json == null) return
        if (suppressed) return  // exiting — don't resurrect the overlay

        val tree = parseTree(json)

        // Router-level plain-screen swaps bump screenKey (chrome roots keep a
        // stable key so their nav stack isn't torn down). Mirrors the shell's
        // embedded shadow-thread path and iOS JumpElementRuntime.
        val rootType = tree.root.type
        val isChromeRoot = rootType == "native_root_stack" || rootType == "native_root_tabs"
        val isCustomNav = NativeUIBridge.navigationPending && !isChromeRoot
        if (isCustomNav) {
            NativeUIBridge.navigationPending = false
            NativeUIBridge.screenKey.intValue++
        }
        NativeUIBridge.currentTree.value = tree
        NativeUIBridge.isActive.value = true
        isActive = true
        Log.d(TAG, "[evt] publish-apply root=$rootType children=${tree.root.children.size}")
    }

    // ─────────────────────────────────────────────────────────────────────
    // Event drain (Element.WaitEvent)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Blocks until an event, [timeoutMs] elapses (negative = block
     * indefinitely), or a newer waitEvent supersedes this one. Returns the
     * event JSON, or null on timeout/supersession.
     */
    fun waitEvent(timeoutMs: Long): JSONObject? {
        lock.lock()
        try {
            waiterGeneration += 1
            val myGen = waiterGeneration
            // Wake any older waiter so it observes it's been superseded and bails.
            notEmpty.signalAll()

            val deadlineNanos = if (timeoutMs < 0) Long.MAX_VALUE
            else System.nanoTime() + timeoutMs * 1_000_000

            while (eventQueue.isEmpty() && myGen == waiterGeneration) {
                if (timeoutMs < 0) {
                    notEmpty.await()
                } else {
                    val remaining = deadlineNanos - System.nanoTime()
                    if (remaining <= 0) return null
                    notEmpty.awaitNanos(remaining)
                    if (eventQueue.isEmpty() && System.nanoTime() >= deadlineNanos) return null
                }
            }

            if (myGen != waiterGeneration) return null  // superseded by a newer waitEvent
            return eventQueue.removeFirstOrNull()
        } finally {
            lock.unlock()
        }
    }

    /**
     * Release any currently-blocked waitEvent so its worker thread can return.
     * The relay calls this synchronously when a NEW WaitEvent arrives so a
     * single-thread executor suffices (the old waiter frees its thread before
     * the new waitEvent runs).
     */
    fun supersedeWaiter() {
        lock.lock()
        try {
            waiterGeneration += 1
            notEmpty.signalAll()
        } finally {
            lock.unlock()
        }
    }

    /** Drain pending events and release any blocked waiter (reset/shutdown). */
    fun reset() {
        lock.lock()
        try {
            eventQueue.clear()
            waiterGeneration += 1
            notEmpty.signalAll()
        } finally {
            lock.unlock()
        }
    }

    /** Keep the current UI visible until the next publish (avoids a blank flash
     *  during hot reload); just drain + release waiters. */
    fun shutdown() = reset()

    // ─────────────────────────────────────────────────────────────────────
    // Event enqueue
    // ─────────────────────────────────────────────────────────────────────

    fun enqueueEvent(event: JSONObject) {
        lock.lock()
        try {
            val type = event.optInt("type", -1)
            if (type in coalescingTypes) {
                val nodeId = event.optInt("node_id", -1)
                val it = eventQueue.iterator()
                while (it.hasNext()) {
                    val e = it.next()
                    if (e.optInt("type", -2) == type && e.optInt("node_id", -2) == nodeId) {
                        it.remove()
                    }
                }
            }
            eventQueue.addLast(event)
            notEmpty.signalAll()
            Log.d(TAG, "[evt] enqueue type=$type cb=${event.optInt("callback_id", -1)} qsize=${eventQueue.size}")
        } finally {
            lock.unlock()
        }
    }

    /**
     * Decode a raw event (as produced by the shell's `NativeElementBridge`
     * send* buffer layout, little-endian) into the JSON event the dev server
     * expects, then enqueue it. This is the single seam the shell's
     * `writeEvent` forks into when a remote session is active.
     */
    fun enqueueRawEvent(type: Int, callbackId: Int, nodeId: Int, data: ByteArray?) {
        val event = JSONObject()
            .put("type", type)
            .put("callback_id", callbackId)
            .put("node_id", nodeId)
        val d = data ?: ByteArray(0)
        when (type) {
            EventType.TEXT_CHANGE, EventType.SUBMIT,
            EventType.RADIO_CHANGE, EventType.SELECT_CHANGE -> {
                val text = decodeLenPrefixedString(d, 0)
                event.put("text", text)
                if (type == EventType.RADIO_CHANGE || type == EventType.SELECT_CHANGE) {
                    event.put("value", text)
                }
            }
            EventType.TOGGLE_CHANGE, EventType.CHECKBOX_CHANGE -> {
                event.put("value", (d.getOrNull(0)?.toInt() ?: 0) != 0)
            }
            EventType.SLIDER_CHANGE -> {
                event.put("value", decodeFloat(d, 0).toDouble())
            }
            EventType.TAB_CHANGE -> {
                event.put("index", decodeU16(d, 0))
            }
            EventType.NATIVE -> {
                // two length-prefixed strings: event name, payload JSON
                val name = decodeLenPrefixedString(d, 0)
                val nameLen = 4 + name.toByteArray(Charsets.UTF_8).size
                val payloadJson = decodeLenPrefixedString(d, nameLen)
                event.put("event", name)
                event.put("payload", try { JSONObject(payloadJson) } catch (e: Exception) { JSONObject() })
            }
            else -> {
                // press / longPress / systemBack / sheetDismiss / hotReload: no data
            }
        }
        enqueueEvent(event)
    }

    /**
     * Deliver a fired native event (Camera.PhotoTaken, MediaSelected, …) to
     * the remote runloop. Mirrors iOS `JumpElementRuntime.sendNativeEvent`.
     */
    fun sendNativeEvent(eventName: String, payloadJson: String) {
        if (!isActive) return
        val payload = try { JSONObject(payloadJson) } catch (e: Exception) { JSONObject() }
        enqueueEvent(
            JSONObject()
                .put("type", EventType.NATIVE)
                .put("callback_id", 0)
                .put("node_id", 0)
                .put("event", eventName)
                .put("payload", payload)
        )
    }

    // ── raw-buffer decode helpers (little-endian) ──

    private fun decodeLenPrefixedString(d: ByteArray, offset: Int): String {
        if (d.size < offset + 4) return ""
        val len = decodeU32(d, offset)
        val start = offset + 4
        if (len <= 0 || d.size < start + len) return ""
        return String(d, start, len, Charsets.UTF_8)
    }

    private fun decodeU16(d: ByteArray, o: Int): Int {
        if (d.size < o + 2) return 0
        return (d[o].toInt() and 0xFF) or ((d[o + 1].toInt() and 0xFF) shl 8)
    }

    private fun decodeU32(d: ByteArray, o: Int): Int {
        if (d.size < o + 4) return 0
        return (d[o].toInt() and 0xFF) or
            ((d[o + 1].toInt() and 0xFF) shl 8) or
            ((d[o + 2].toInt() and 0xFF) shl 16) or
            ((d[o + 3].toInt() and 0xFF) shl 24)
    }

    private fun decodeFloat(d: ByteArray, o: Int): Float {
        if (d.size < o + 4) return 0f
        return ByteBuffer.wrap(d, o, 4).order(ByteOrder.LITTLE_ENDIAN).float
    }

    // ─────────────────────────────────────────────────────────────────────
    // JSON → NativeUINode tree parser (mirrors iOS/Air JumpElementBridge)
    // ─────────────────────────────────────────────────────────────────────

    fun parseTree(json: JSONObject): NativeUITree =
        NativeUITree(version = 1, callbackCount = 0, root = parseNode(json))

    private fun parseNode(json: JSONObject): NativeUINode {
        val id = json.optInt("id", 0)
        val type = json.optString("type", "column")
        val layout = json.optJSONObject("layout")?.let { parseLayout(it) } ?: defaultLayout
        val style = json.optJSONObject("style")?.let { parseStyle(it) }
        val props = GenericProps(json.optJSONObject("props")?.let { jsonToMap(it) } ?: emptyMap())
        val onPress = json.optInt("on_press", 0)
        val onLongPress = json.optInt("on_long_press", 0)
        val children = json.optJSONArray("children")?.let { arr ->
            (0 until arr.length()).mapNotNull { arr.optJSONObject(it)?.let(::parseNode) }
        } ?: emptyList()
        return NativeUINode(id, type, layout, style, props, onPress, onLongPress, children)
    }

    private fun parseLayout(d: JSONObject): NodeLayout {
        val (w, wMode) = parseSize(d.opt("width"))
        val (h, hMode) = parseSize(d.opt("height"))
        val (pT, pR, pB, pL) = parseEdges(d.opt("padding"))
        val (mT, mR, mB, mL) = parseEdges(d.opt("margin"))
        val (posT, posR, posB, posL) = parseEdges(d.opt("position"))
        return NodeLayout(
            width = w, widthMode = wMode, height = h, heightMode = hMode,
            paddingTop = floatOr(d, pT, "paddingTop", "padding_top"),
            paddingRight = floatOr(d, pR, "paddingRight", "padding_right"),
            paddingBottom = floatOr(d, pB, "paddingBottom", "padding_bottom"),
            paddingLeft = floatOr(d, pL, "paddingLeft", "padding_left"),
            marginTop = floatOr(d, mT, "marginTop", "margin_top"),
            marginRight = floatOr(d, mR, "marginRight", "margin_right"),
            marginBottom = floatOr(d, mB, "marginBottom", "margin_bottom"),
            marginLeft = floatOr(d, mL, "marginLeft", "margin_left"),
            flexGrow = floatAny(d, "flex_grow", "flexGrow"),
            flexShrink = floatAny(d, "flex_shrink", "flexShrink"),
            alignSelf = intAny(d, "align_self", "alignSelf"),
            alignItems = intAny(d, "align_items", "alignItems"),
            justifyContent = intAny(d, "justify_content", "justifyContent"),
            gap = floatVal(d, "gap"),
            safeArea = intAny(d, "safe_area", "safeArea"),
            minWidth = floatAny(d, "min_width", "minWidth"),
            minHeight = floatAny(d, "min_height", "minHeight"),
            maxWidth = floatAny(d, "max_width", "maxWidth"),
            maxHeight = floatAny(d, "max_height", "maxHeight"),
            flexBasis = floatAny(d, "flex_basis", "flexBasis"),
            flexBasisMode = intAny(d, "flex_basis_mode", "flexBasisMode"),
            flexWrap = intAny(d, "flex_wrap", "flexWrap"),
            flexDirection = intAny(d, "flex_direction", "flexDirection"),
            positionType = intAny(d, "position_type", "positionType"),
            positionTop = posT, positionRight = posR, positionBottom = posB, positionLeft = posL,
            display = intVal(d, "display"),
            overflow = intVal(d, "overflow"),
            alignContent = intAny(d, "align_content", "alignContent"),
            direction = intVal(d, "direction"),
            aspectRatio = floatAny(d, "aspect_ratio", "aspectRatio"),
            rowGap = floatAny(d, "row_gap", "rowGap")
        )
    }

    private fun parseStyle(d: JSONObject): NodeStyle = NodeStyle(
        bgColor = colorVal(d, "bg_color"),
        borderRadius = floatVal(d, "border_radius"),
        borderWidth = floatVal(d, "border_width"),
        borderColor = colorVal(d, "border_color"),
        opacity = if (d.has("opacity")) floatVal(d, "opacity") else 1f,
        elevation = floatVal(d, "elevation")
    )

    private val defaultLayout = NodeLayout(
        0f, 0, 0f, 0, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0f, 0, 0, 0, 0f
    )

    // SizeMode: 0=fixed, 1=wrap, 2=fill (matches PHP)
    private fun parseSize(value: Any?): Pair<Float, Int> = when (value) {
        is String -> when (value) {
            "fill" -> 0f to 2
            else -> 0f to 1
        }
        is Number -> { val f = value.toFloat(); if (f > 0) f to 0 else 0f to 1 }
        else -> 0f to 1
    }

    private data class Edges(val t: Float, val r: Float, val b: Float, val l: Float)
    private operator fun Edges.component1() = t
    private operator fun Edges.component2() = r
    private operator fun Edges.component3() = b
    private operator fun Edges.component4() = l

    private fun parseEdges(value: Any?): Edges = when (value) {
        is Number -> { val f = value.toFloat(); Edges(f, f, f, f) }
        is JSONArray -> {
            val a = (0 until value.length()).map { value.optDouble(it, 0.0).toFloat() }
            when (a.size) {
                1 -> Edges(a[0], a[0], a[0], a[0])
                2 -> Edges(a[0], a[1], a[0], a[1])
                3 -> Edges(a[0], a[1], a[2], a[1])
                4 -> Edges(a[0], a[1], a[2], a[3])
                else -> Edges(0f, 0f, 0f, 0f)
            }
        }
        else -> Edges(0f, 0f, 0f, 0f)
    }

    private fun floatVal(d: JSONObject, key: String): Float =
        if (d.has(key)) d.optDouble(key, 0.0).toFloat() else 0f

    private fun floatAny(d: JSONObject, vararg keys: String): Float {
        for (k in keys) if (d.has(k)) return floatVal(d, k)
        return 0f
    }

    private fun floatOr(d: JSONObject, fallback: Float, vararg keys: String): Float {
        for (k in keys) if (d.has(k)) return floatVal(d, k)
        return fallback
    }

    private fun intVal(d: JSONObject, key: String): Int = if (d.has(key)) d.optInt(key, 0) else 0

    private fun intAny(d: JSONObject, vararg keys: String): Int {
        for (k in keys) if (d.has(k)) return intVal(d, k)
        return 0
    }

    private fun colorVal(d: JSONObject, key: String): Int {
        val v = d.opt(key) ?: return 0
        return when (v) {
            is Number -> v.toInt()
            is String -> ColorParser.parse(v)
            else -> 0
        }
    }

    private fun jsonToMap(obj: JSONObject): Map<String, Any> {
        val map = HashMap<String, Any>()
        val keys = obj.keys()
        while (keys.hasNext()) {
            val k = keys.next()
            val v = obj.opt(k) ?: continue
            map[k] = when (v) {
                is JSONObject -> jsonToMap(v)
                is JSONArray -> jsonToList(v)
                else -> v
            }
        }
        return map
    }

    private fun jsonToList(arr: JSONArray): List<Any> =
        (0 until arr.length()).mapNotNull { i ->
            when (val v = arr.opt(i)) {
                null -> null
                is JSONObject -> jsonToMap(v)
                is JSONArray -> jsonToList(v)
                else -> v
            }
        }
}
