import UIKit

/// A global three-finger swipe-right that exits a connected remote app back
/// to the Jump home.
///
/// Attached as a `UISwipeGestureRecognizer` on the key window, so it fires
/// over ANY content: the local native-ui tree, the WebView, or a remote app
/// rendered through Jump. `cancelsTouchesInView = false`, no delayed touches,
/// and simultaneous recognition with everything mean it observes every touch
/// without stealing any from the app's own gestures.
///
/// This used to live in the NativePHP shell (as `EscapeHatchGesture`, posting
/// a `JumpEscapeHatch` notification that `JumpBridgeRelay` observed). It is
/// Jump-only, so it now ships here and calls `JumpBridgeRelay.exitToJump()`
/// directly. The name differs from the shell's type so both can coexist while
/// the app still builds against a core that ships the old one; nothing
/// observes that notification any more, so the old recognizer is inert.
///
/// Installed at app launch (`jumpClientPluginInit`) and again whenever a
/// window becomes key, so every way into a remote session can be swiped out
/// of: Connect, a QR scan, or a `jump://webview` deep link. Installing is
/// idempotent; firing is a no-op unless a remote session is live
/// (`JumpBridgeRelay.exitToJump` guards).
final class JumpEscapeHatchGesture: NSObject, UIGestureRecognizerDelegate {

    private static let shared = JumpEscapeHatchGesture()

    private weak var installedOn: UIWindow?
    private var recognizer: UISwipeGestureRecognizer?

    private var observingWindows = false

    /// Attach the recognizer to the key window once; safe to call repeatedly.
    /// Must be called on the main thread.
    static func install() {
        shared.attach()
    }

    /// Install now if there is a key window, and on every window that
    /// becomes key later. Plugin init runs before the first window exists,
    /// which is why the observer is needed at all.
    static func installForAppLifetime() {
        DispatchQueue.main.async {
            shared.observeWindows()
            shared.attach()
        }
    }

    private func observeWindows() {
        guard !observingWindows else { return }
        observingWindows = true

        NotificationCenter.default.addObserver(
            forName: UIWindow.didBecomeKeyNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            self?.attach()
        }
    }

    private func attach() {
        guard let window = Self.keyWindow() else { return }
        if installedOn === window, recognizer != nil { return }

        if let old = recognizer {
            old.view?.removeGestureRecognizer(old)
        }

        let swipe = UISwipeGestureRecognizer(target: self, action: #selector(fired))
        swipe.direction = .right
        swipe.numberOfTouchesRequired = 3
        swipe.cancelsTouchesInView = false
        swipe.delaysTouchesBegan = false
        swipe.delegate = self
        window.addGestureRecognizer(swipe)

        recognizer = swipe
        installedOn = window
        print("🖐️ 3-finger escape hatch installed")
    }

    private static func keyWindow() -> UIWindow? {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap { $0.windows }
            .first { $0.isKeyWindow }
    }

    @objc private func fired() {
        print("🖐️ 3-finger swipe-right — escape hatch")
        JumpBridgeRelay.shared.exitToJump()
    }

    // Coexist with the app's own gestures rather than competing with them.
    func gestureRecognizer(_ g: UIGestureRecognizer,
                           shouldRecognizeSimultaneouslyWith other: UIGestureRecognizer) -> Bool { true }
}

/// Plugin init (nativephp.json `ios.init_function`), called once at launch.
func jumpClientPluginInit() {
    JumpEscapeHatchGesture.installForAppLifetime()
}
