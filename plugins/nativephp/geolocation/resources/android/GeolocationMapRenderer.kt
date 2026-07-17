package com.nativephp.geolocation

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.Paint
import android.graphics.PointF
import android.util.Log
import android.view.MotionEvent
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.core.content.res.ResourcesCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.nativephp.mobile.R
import com.nativephp.mobile.ui.getIconName
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.ui.nativerender.NativeUINode
import org.json.JSONArray
import org.maplibre.android.MapLibre
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.location.LocationComponentActivationOptions
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.LineString
import org.maplibre.geojson.Point

/**
 * Renders the `map` element via MapLibre GL + OpenFreeMap vector tiles —
 * fully keyless (the iOS counterpart is MapKit, also keyless).
 *
 * Props (see NativePhp\Geolocation\Elements\Map): lat/lng as STRING props
 * (the wire float is 32-bit — string keeps double precision and makes the
 * camera-change key stable), zoom, interactive, show_user_location,
 * polyline (JSON [[lat,lng],...]), polyline_color/width. Markers are
 * `map_marker` child nodes read as data; each carries its own on_press.
 *
 * Everything that touches sources/layers/camera/location is gated on the
 * async style-loaded callback, and prop application is guarded by
 * `applied*` keys so recompositions are no-ops — otherwise every publish
 * would reset the camera.
 */
object GeolocationMapRenderer {

    private const val TAG = "Geolocation.Map"
    private const val STYLE_URL = "https://tiles.openfreemap.org/styles/liberty"
    private const val TRACK_SOURCE = "nphp-track"
    private const val TRACK_LAYER = "nphp-track-layer"
    private const val MARKER_SOURCE = "nphp-markers"
    private const val MARKER_LAYER = "nphp-markers-layer"
    private const val MARKER_ICON_LAYER = "nphp-markers-icon-layer"

    /** Mutable per-map state surviving recomposition. */
    private class MapState {
        var map: MapLibreMap? = null
        var style: Style? = null
        var appliedCameraKey = ""
        var appliedPolyline: String? = null
        var appliedMarkersKey: String? = null
        var appliedZoom: Double? = null
        var locationEnabled = false
        var interactive = true

        /** Icon bitmaps already registered with the style (key = icon+color). */
        val addedIconKeys = mutableSetOf<String>()
    }

    @SuppressLint("ClickableViewAccessibility")
    @Composable
    fun Render(node: NativeUINode, modifier: Modifier) {
        val context = LocalContext.current
        // The compose-ui alias is deprecated (moved to the
        // lifecycle-runtime-compose artifact) but still functional;
        // using it avoids adding a Gradle dependency for one symbol.
        @Suppress("DEPRECATION")
        val lifecycleOwner = LocalLifecycleOwner.current

        val state = remember { MapState() }
        val mapView = remember {
            // Idempotent; must run before the first MapView is created.
            MapLibre.getInstance(context)
            MapView(context).apply {
                onCreate(null)
                // The map usually lives inside a scroll-view; without this
                // the Compose scroll container intercepts drags and the map
                // can't pan or pinch. While a touch is down on an
                // interactive map, the scroll parent must not steal it.
                setOnTouchListener { v, event ->
                    when (event.actionMasked) {
                        MotionEvent.ACTION_DOWN ->
                            v.parent?.requestDisallowInterceptTouchEvent(state.interactive)
                        MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL ->
                            v.parent?.requestDisallowInterceptTouchEvent(false)
                    }
                    false // MapView still handles the gesture itself
                }
            }
        }

        // Forward the host lifecycle — without this the GL surface never
        // starts (blank map) or leaks on screen exit.
        DisposableEffect(lifecycleOwner) {
            val observer = LifecycleEventObserver { _, event ->
                when (event) {
                    Lifecycle.Event.ON_START -> mapView.onStart()
                    Lifecycle.Event.ON_RESUME -> mapView.onResume()
                    Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                    Lifecycle.Event.ON_STOP -> mapView.onStop()
                    else -> {}
                }
            }
            lifecycleOwner.lifecycle.addObserver(observer)
            onDispose {
                lifecycleOwner.lifecycle.removeObserver(observer)
                mapView.onPause()
                mapView.onStop()
                mapView.onDestroy()
            }
        }

        // Snapshot current props/children for this composition.
        val p = node.props
        val lat = p.getString("lat").toDoubleOrNull()
        val lng = p.getString("lng").toDoubleOrNull()
        val zoom = p.getFloat("zoom", 13f).toDouble()
        val interactive = if (p.has("interactive")) p.getBool("interactive") else true
        val follow = if (p.has("follow")) p.getBool("follow") else true
        val showUser = p.getBool("show_user_location")
        val polyline = p.getString("polyline", "")
        val polylineColor = p.getString("polyline_color", "#FC4C02")
        val polylineWidth = p.getFloat("polyline_width", 4f)
        val markers = node.children.filter { it.type == "map_marker" }
        val cameraKey = "$lat,$lng,$zoom"
        val markersKey = markers.joinToString("|") {
            "${it.id}:${it.props.getString("lat")}:${it.props.getString("lng")}:${it.props.getString("color")}:${it.props.getString("icon")}"
        }

        // (Re)apply props whenever the composition or the style says so.
        val apply = {
            val map = state.map
            val style = state.style
            state.interactive = interactive
            if (map != null && style != null) {
                map.uiSettings.setAllGesturesEnabled(interactive)
                applyPolyline(state, polyline)
                applyMarkers(context, state, markersKey, markers)
                applyCamera(state, cameraKey, lat, lng, zoom, polyline, follow)
                applyUserLocation(context, state, showUser)
            }
        }

        AndroidView(
            modifier = modifier,
            factory = {
                mapView.getMapAsync { map ->
                    state.map = map
                    map.setStyle(Style.Builder().fromUri(STYLE_URL)) { style ->
                        state.style = style

                        style.addSource(GeoJsonSource(TRACK_SOURCE))
                        style.addLayer(
                            LineLayer(TRACK_LAYER, TRACK_SOURCE).withProperties(
                                PropertyFactory.lineColor(polylineColor),
                                PropertyFactory.lineWidth(polylineWidth),
                                PropertyFactory.lineCap("round"),
                                PropertyFactory.lineJoin("round"),
                            )
                        )

                        style.addSource(GeoJsonSource(MARKER_SOURCE))
                        // Dot markers (no icon prop) …
                        style.addLayer(
                            CircleLayer(MARKER_LAYER, MARKER_SOURCE).withProperties(
                                PropertyFactory.circleRadius(8f),
                                PropertyFactory.circleColor(Expression.toColor(Expression.get("color"))),
                                PropertyFactory.circleStrokeColor("#FFFFFF"),
                                PropertyFactory.circleStrokeWidth(2f),
                            ).withFilter(Expression.not(Expression.has("iconKey")))
                        )
                        // … and icon markers: pre-tinted rasterized Material
                        // glyphs registered per icon+color via style.addImage.
                        style.addLayer(
                            SymbolLayer(MARKER_ICON_LAYER, MARKER_SOURCE).withProperties(
                                PropertyFactory.iconImage(Expression.get("iconKey")),
                                PropertyFactory.iconAllowOverlap(true),
                                PropertyFactory.iconIgnorePlacement(true),
                            ).withFilter(Expression.has("iconKey"))
                        )

                        // Marker taps: hit-test the marker layer, dispatch the
                        // feature's PHP callback id.
                        map.addOnMapClickListener { latLng ->
                            val screen: PointF = map.projection.toScreenLocation(latLng)
                            val hit = map.queryRenderedFeatures(screen, MARKER_LAYER, MARKER_ICON_LAYER).firstOrNull()
                            if (hit != null) {
                                val cb = hit.getNumberProperty("cb")?.toInt() ?: 0
                                val nodeId = hit.getNumberProperty("nodeId")?.toInt() ?: 0
                                if (cb != 0) {
                                    NativeElementBridge.sendPressEvent(cb, nodeId)
                                    return@addOnMapClickListener true
                                }
                            }
                            false
                        }

                        apply()
                    }
                }
                mapView
            },
            update = { apply() }
        )
    }

    private fun applyPolyline(state: MapState, polyline: String) {
        if (polyline == state.appliedPolyline) return
        state.appliedPolyline = polyline

        val source = state.style?.getSourceAs<GeoJsonSource>(TRACK_SOURCE) ?: return
        val points = parsePairs(polyline).map { Point.fromLngLat(it.second, it.first) }
        if (points.size >= 2) {
            source.setGeoJson(Feature.fromGeometry(LineString.fromLngLats(points)))
        } else {
            source.setGeoJson(FeatureCollection.fromFeatures(emptyList()))
        }
    }

    private fun applyMarkers(
        context: Context,
        state: MapState,
        markersKey: String,
        markers: List<NativeUINode>,
    ) {
        if (markersKey == state.appliedMarkersKey) return
        state.appliedMarkersKey = markersKey

        val style = state.style ?: return
        val source = style.getSourceAs<GeoJsonSource>(MARKER_SOURCE) ?: return
        val features = markers.mapNotNull { child ->
            val lat = child.props.getString("lat").toDoubleOrNull() ?: return@mapNotNull null
            val lng = child.props.getString("lng").toDoubleOrNull() ?: return@mapNotNull null
            Feature.fromGeometry(Point.fromLngLat(lng, lat)).apply {
                addStringProperty("color", child.props.getString("color", "#EA4335"))
                addNumberProperty("cb", child.onPress)
                addNumberProperty("nodeId", child.id)

                // Icon markers: register a pre-tinted glyph bitmap once per
                // icon+color combo and reference it from the feature.
                val icon = child.props.getString("icon")
                if (icon.isNotEmpty()) {
                    val colorInt = child.props.getColor("color", 0xFFEA4335.toInt())
                    val key = "nphp-icon-$icon-${Integer.toHexString(colorInt)}"
                    if (state.addedIconKeys.add(key)) {
                        try {
                            style.addImage(key, iconBitmap(context, icon, colorInt))
                        } catch (e: Exception) {
                            Log.w(TAG, "icon rasterize failed for '$icon': ${e.message}")
                            state.addedIconKeys.remove(key)
                        }
                    }
                    if (state.addedIconKeys.contains(key)) {
                        addStringProperty("iconKey", key)
                    }
                }
            }
        }
        source.setGeoJson(FeatureCollection.fromFeatures(features))
    }

    /**
     * Rasterize a Material icon ligature glyph to a tinted bitmap for
     * MapLibre's SymbolLayer. Uses the same bundled ligature font and
     * name-normalization as the Compose MaterialIcon composable, so any
     * name that works on a <button icon="..."> works here.
     */
    private fun iconBitmap(context: Context, name: String, colorInt: Int): Bitmap {
        // Size in dp × display density: MapLibre draws the bitmap at its
        // raw pixel size, so density scaling is on us — a fixed-px bitmap
        // renders tiny on high-density screens.
        val density = context.resources.displayMetrics.density
        val glyphPx = 32f * density
        val sizePx = (40f * density).toInt()

        val paint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            typeface = ResourcesCompat.getFont(context, R.font.material_icons)
            textSize = glyphPx
            color = colorInt
            textAlign = Paint.Align.CENTER
            fontFeatureSettings = "'liga' on"
        }

        val bitmap = Bitmap.createBitmap(sizePx, sizePx, Bitmap.Config.ARGB_8888)
        val canvas = android.graphics.Canvas(bitmap)
        val baseline = sizePx / 2f - (paint.descent() + paint.ascent()) / 2f
        canvas.drawText(getIconName(name), sizePx / 2f, baseline, paint)

        return bitmap
    }

    private fun applyCamera(
        state: MapState,
        cameraKey: String,
        lat: Double?,
        lng: Double?,
        zoom: Double,
        polyline: String,
        follow: Boolean,
    ) {
        if (cameraKey == state.appliedCameraKey) return
        val firstApply = state.appliedCameraKey.isEmpty()
        val zoomChanged = state.appliedZoom != null && state.appliedZoom != zoom
        state.appliedCameraKey = cameraKey
        state.appliedZoom = zoom

        val map = state.map ?: return

        // With follow off, the user owns position: new fixes must not yank
        // the camera. Explicit zoom changes still apply — at the user's
        // current center, not the fix.
        if (!follow && !firstApply) {
            if (zoomChanged) {
                map.moveCamera(CameraUpdateFactory.zoomTo(zoom))
            }
            return
        }

        if (lat != null && lng != null) {
            map.moveCamera(CameraUpdateFactory.newLatLngZoom(LatLng(lat, lng), zoom))
            return
        }

        // No explicit center — fit the track when there is one.
        val points = parsePairs(polyline)
        if (points.size >= 2) {
            val bounds = LatLngBounds.Builder()
                .apply { points.forEach { include(LatLng(it.first, it.second)) } }
                .build()
            map.moveCamera(CameraUpdateFactory.newLatLngBounds(bounds, 64))
        }
    }

    private fun applyUserLocation(context: Context, state: MapState, showUser: Boolean) {
        if (!showUser || state.locationEnabled) return
        val map = state.map ?: return
        val style = state.style ?: return

        val granted =
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED
        if (!granted) return

        try {
            map.locationComponent.activateLocationComponent(
                LocationComponentActivationOptions.builder(context, style).build()
            )
            @Suppress("MissingPermission")
            map.locationComponent.isLocationComponentEnabled = true
            state.locationEnabled = true
        } catch (e: Exception) {
            Log.w(TAG, "location component unavailable: ${e.message}")
        }
    }

    /** "[[lat,lng],...]" → list of (lat, lng). Malformed input → empty. */
    private fun parsePairs(json: String): List<Pair<Double, Double>> {
        if (json.isEmpty()) return emptyList()
        return try {
            val arr = JSONArray(json)
            (0 until arr.length()).mapNotNull { i ->
                val pair = arr.optJSONArray(i) ?: return@mapNotNull null
                Pair(pair.getDouble(0), pair.getDouble(1))
            }
        } catch (e: Exception) {
            Log.w(TAG, "bad polyline json: ${e.message}")
            emptyList()
        }
    }
}
