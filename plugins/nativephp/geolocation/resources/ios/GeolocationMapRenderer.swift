import SwiftUI
import MapKit

/// Renders the `map` element via SwiftUI MapKit — fully keyless (the
/// Android counterpart is MapLibre + OpenFreeMap, also keyless).
///
/// Props (see NativePhp\Geolocation\Elements\Map): lat/lng as STRING props
/// (the wire float is 32-bit — strings keep double precision and make the
/// camera-change key stable), zoom, style, interactive, show_user_location,
/// polyline (JSON [[lat,lng],...]), polyline_color/width. Markers are
/// `map_marker` child nodes read as data; each carries its own on_press.
///
/// Camera state lives in a @StateObject so recompositions never reset it;
/// the camera moves only when the "$lat,$lng,$zoom" key actually changes.
struct GeolocationMapRenderer: View {
    let node: NativeUINode
    @StateObject private var model = GeolocationMapModel()

    var body: some View {
        let p = node.props
        let lat = Double(p.getString("lat"))
        let lng = Double(p.getString("lng"))
        let zoom = Double(p.getFloat("zoom", default: 13))
        let interactive = p.has("interactive") ? p.getBool("interactive") : true
        let follow = p.has("follow") ? p.getBool("follow") : true
        let styleName = p.getString("style", default: "standard")
        let showUser = p.getBool("show_user_location")
        let polylineColor = p.getColor("polyline_color", default: 0xFFFC4C02)
        let polylineWidth = CGFloat(p.getFloat("polyline_width", default: 4))
        let track = Self.parsePairs(p.getString("polyline"))
        let markers = node.children.filter { $0.type == "map_marker" }
        let cameraKey = "\(lat ?? .nan),\(lng ?? .nan),\(zoom)"

        Map(position: $model.camera, interactionModes: interactive ? .all : []) {
            if showUser {
                UserAnnotation()
            }

            if track.count >= 2 {
                MapPolyline(coordinates: track)
                    .stroke(
                        Self.color(argb: polylineColor),
                        style: StrokeStyle(lineWidth: polylineWidth, lineCap: .round, lineJoin: .round)
                    )
            }

            ForEach(markers, id: \.id) { marker in
                if let mLat = Double(marker.props.getString("lat")),
                   let mLng = Double(marker.props.getString("lng")) {
                    Annotation(
                        marker.props.getString("title"),
                        coordinate: CLLocationCoordinate2D(latitude: mLat, longitude: mLng)
                    ) {
                        GeolocationMarkerView(
                            icon: marker.props.getString("icon"),
                            color: Self.color(argb: marker.props.getColor("color", default: 0xFFEA4335))
                        )
                        .onTapGesture {
                            if marker.onPress != 0 {
                                NativeElementBridge.sendPressEvent(marker.onPress, nodeId: marker.id)
                            }
                        }
                    }
                }
            }
        }
        .mapStyle(Self.mapStyle(styleName))
        .onMapCameraChange(frequency: .onEnd) { context in
            // Remember where the user actually is, so zoom-only changes
            // with follow off can pivot in place instead of recentering.
            model.lastCamera = context.camera
        }
        .onAppear { model.applyCamera(key: cameraKey, lat: lat, lng: lng, zoom: zoom, track: track, follow: follow) }
        .onChange(of: cameraKey) { _, _ in
            model.applyCamera(key: cameraKey, lat: lat, lng: lng, zoom: zoom, track: track, follow: follow)
        }
    }

    private static func mapStyle(_ name: String) -> MapStyle {
        switch name {
        case "satellite": return .imagery
        case "hybrid": return .hybrid
        default: return .standard
        }
    }

    private static func color(argb: Int) -> Color {
        Color(
            red: Double((argb >> 16) & 0xFF) / 255.0,
            green: Double((argb >> 8) & 0xFF) / 255.0,
            blue: Double(argb & 0xFF) / 255.0,
            opacity: Double((argb >> 24) & 0xFF) / 255.0
        )
    }

    /// "[[lat,lng],...]" → coordinates. Malformed input → empty.
    private static func parsePairs(_ json: String) -> [CLLocationCoordinate2D] {
        guard !json.isEmpty,
              let data = json.data(using: .utf8),
              let pairs = try? JSONSerialization.jsonObject(with: data) as? [[Double]] else {
            return []
        }
        return pairs.compactMap { pair in
            guard pair.count >= 2 else { return nil }
            return CLLocationCoordinate2D(latitude: pair[0], longitude: pair[1])
        }
    }
}

/// Camera state surviving recomposition. `applyCamera` early-returns when
/// the key is unchanged so republished trees never reset pan/zoom the user
/// did by hand.
final class GeolocationMapModel: ObservableObject {
    @Published var camera: MapCameraPosition = .automatic
    /// Where the user's pan/zoom left the map (fed by onMapCameraChange).
    var lastCamera: MapCamera?
    private var appliedKey = ""
    private var appliedZoom: Double?

    func applyCamera(key: String, lat: Double?, lng: Double?, zoom: Double, track: [CLLocationCoordinate2D], follow: Bool) {
        guard key != appliedKey else { return }
        let firstApply = appliedKey.isEmpty
        let zoomChanged = appliedZoom != nil && appliedZoom != zoom
        appliedKey = key
        appliedZoom = zoom

        // With follow off, the user owns position: new fixes must not yank
        // the camera. Explicit zoom changes still apply — pivoting at the
        // user's current center, not the fix.
        if !follow && !firstApply {
            if zoomChanged {
                let center = lastCamera?.centerCoordinate
                    ?? lat.flatMap { la in lng.map { CLLocationCoordinate2D(latitude: la, longitude: $0) } }
                if let center = center {
                    camera = .camera(MapCamera(
                        centerCoordinate: center,
                        distance: 591_657_550.0 / pow(2.0, zoom)
                    ))
                }
            }
            return
        }

        if let lat = lat, let lng = lng {
            // Web-mercator zoom level → MapKit camera distance.
            let distance = 591_657_550.0 / pow(2.0, zoom)
            camera = .camera(MapCamera(
                centerCoordinate: CLLocationCoordinate2D(latitude: lat, longitude: lng),
                distance: distance
            ))
        } else if track.count >= 2 {
            // No explicit center — fit the track.
            camera = .automatic
        }
    }
}

/// One marker's visual: an SF Symbol when the `icon` prop is set (the
/// platform triple resolves it on the PHP side), else the default
/// colored dot. Both tinted with the marker's `color`.
struct GeolocationMarkerView: View {
    let icon: String
    let color: Color

    var body: some View {
        if !icon.isEmpty {
            Image(systemName: icon)
                .font(.system(size: 22, weight: .semibold))
                .foregroundStyle(color)
                .shadow(color: .white, radius: 1)
        } else {
            Circle()
                .fill(color)
                .frame(width: 16, height: 16)
                .overlay(Circle().stroke(.white, lineWidth: 2))
                .shadow(radius: 1)
        }
    }
}

/// No-op renderer for data-only child elements (`map_marker`). The parent
/// map renderer consumes marker children as data; a marker should never
/// paint anything if it somehow renders standalone.
struct GeolocationEmptyRenderer: View {
    let node: NativeUINode

    var body: some View {
        EmptyView()
    }
}
