package com.nativephp.discovery

import android.app.Activity
import android.util.Log
import android.view.MotionEvent
import android.view.Window

/**
 * Android port of the shell's iOS `EscapeHatchGesture`: a global 3-finger
 * swipe-right that exits a connected remote app back to the Jump home.
 *
 * iOS attaches a `UISwipeGestureRecognizer` to the key window with
 * `cancelsTouchesInView = false` so it observes every touch without stealing
 * any. Android has no window-level recognizer, so this wraps the Activity
 * window's [Window.Callback] and watches [dispatchTouchEvent] — which sees
 * every touch BEFORE the view tree (WebView or Compose) consumes it. Events
 * are always delegated unmodified; the gesture never consumes anything.
 *
 * Installed idempotently from `DiscoveryFunctions.Start`/`Connect` (main
 * thread). It stays installed for the Activity's lifetime; firing is a no-op
 * unless a remote session is live ([JumpBridgeRelay.exitToJump] guards).
 */
object EscapeHatchGesture {

    private const val TAG = "EscapeHatchGesture"

    /** Horizontal travel (dp) of the 3-finger centroid that triggers the exit. */
    private const val SWIPE_DISTANCE_DP = 110f

    /** Max vertical drift (dp) before the gesture stops counting as a swipe-right. */
    private const val MAX_VERTICAL_DRIFT_DP = 90f

    private var installedOn: Window? = null

    /** Wrap the activity window's callback once; safe to call repeatedly. */
    fun install(activity: Activity) {
        val window = activity.window ?: return
        if (installedOn === window) return

        val density = activity.resources.displayMetrics.density
        val existing = window.callback ?: return
        window.callback = EscapeCallback(existing, density)
        installedOn = window
        Log.i(TAG, "3-finger escape hatch installed")
    }

    /**
     * Observe-only wrapper: every member delegates to [wrapped]; we peek at
     * touch events on their way through.
     */
    private class EscapeCallback(
        private val wrapped: Window.Callback,
        density: Float,
    ) : Window.Callback by wrapped {

        private val swipeDistancePx = SWIPE_DISTANCE_DP * density
        private val maxDriftPx = MAX_VERTICAL_DRIFT_DP * density

        private var tracking = false
        private var fired = false
        private var startX = 0f
        private var startY = 0f

        override fun dispatchTouchEvent(event: MotionEvent): Boolean {
            when (event.actionMasked) {
                MotionEvent.ACTION_POINTER_DOWN -> when {
                    event.pointerCount == 3 -> {
                        tracking = true
                        startX = centroidX(event)
                        startY = centroidY(event)
                    }
                    // A 4th finger cancels — this is strictly a 3-finger gesture.
                    event.pointerCount > 3 -> tracking = false
                }

                MotionEvent.ACTION_MOVE -> if (tracking && !fired && event.pointerCount == 3) {
                    val dx = centroidX(event) - startX
                    val dy = centroidY(event) - startY
                    if (kotlin.math.abs(dy) > maxDriftPx) {
                        tracking = false
                    } else if (dx > swipeDistancePx) {
                        fired = true
                        tracking = false
                        Log.i(TAG, "3-finger swipe-right — escape hatch")
                        JumpBridgeRelay.exitToJump()
                    }
                }

                MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                    tracking = false
                    fired = false
                }
            }

            return wrapped.dispatchTouchEvent(event)
        }

        private fun centroidX(e: MotionEvent): Float {
            var sum = 0f
            for (i in 0 until e.pointerCount) sum += e.getX(i)
            return sum / e.pointerCount
        }

        private fun centroidY(e: MotionEvent): Float {
            var sum = 0f
            for (i in 0 until e.pointerCount) sum += e.getY(i)
            return sum / e.pointerCount
        }
    }
}
