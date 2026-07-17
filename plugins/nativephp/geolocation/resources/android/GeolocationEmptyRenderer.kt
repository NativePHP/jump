package com.nativephp.geolocation

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.nativephp.mobile.ui.nativerender.NativeUINode

/**
 * No-op renderer for data-only child elements (`map_marker`). The parent
 * map renderer consumes marker children as data; a marker should never
 * paint anything if it somehow renders standalone.
 */
object GeolocationEmptyRenderer {
    @Composable
    fun Render(node: NativeUINode, modifier: Modifier) {
    }
}
