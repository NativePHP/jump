<?php

namespace NativePHP\Geolocation\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Map — embedded native map. Renders as SwiftUI MapKit on iOS and a
 * MapLibre GL MapView (OpenFreeMap tiles) on Android — no API keys on
 * either platform.
 *
 * Camera: `lat`/`lng`/`zoom` position the map; when omitted, the camera
 * fits the polyline (or platform default). Re-rendering with new lat/lng
 * moves the camera, so binding them to a live fix follows the user.
 *
 * `polyline` accepts either bare pairs ([[lat, lng], ...]) or the fix maps
 * Geolocation::drainWatch() returns ({latitude, longitude, ...}) — points
 * are normalized and rounded to 6 decimals before hitting the wire, so
 * pass drained fixes straight through. Keep it under ~1000 points; the
 * prop re-ships on every publish.
 *
 * Markers are `<map-marker>` children; each carries its own `@press`.
 *
 * Lat/lng travel as STRING props: the wire's float type is 32-bit, which
 * costs ~1m of coordinate precision and breaks camera-change detection.
 */
class Map extends Element
{
    protected string $type = 'map';

    /** @var array<string, mixed> */
    protected array $mapProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['lat'])) {
            $this->lat((float) $attrs['lat']);
        }
        if (isset($attrs['lng'])) {
            $this->lng((float) $attrs['lng']);
        }
        if (isset($attrs['zoom'])) {
            $this->zoom((float) $attrs['zoom']);
        }
        if (isset($attrs['style'])) {
            $this->mapStyle($attrs['style']);
        }
        if (isset($attrs['interactive'])) {
            $this->interactive(filter_var($attrs['interactive'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($attrs['follow'])) {
            $this->follow(filter_var($attrs['follow'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($attrs['polyline'])) {
            $this->polyline($attrs['polyline']);
        }

        foreach (['show-user-location', 'showUserLocation'] as $key) {
            if (isset($attrs[$key])) {
                $this->showUserLocation(filter_var($attrs[$key], FILTER_VALIDATE_BOOLEAN));
            }
        }
        foreach (['polyline-color', 'polylineColor'] as $key) {
            if (isset($attrs[$key])) {
                $this->polylineColor($attrs[$key]);
            }
        }
        foreach (['polyline-width', 'polylineWidth'] as $key) {
            if (isset($attrs[$key])) {
                $this->polylineWidth((float) $attrs[$key]);
            }
        }
    }

    public function lat(float $lat): static
    {
        $this->mapProps['lat'] = sprintf('%.7F', $lat);

        return $this;
    }

    public function lng(float $lng): static
    {
        $this->mapProps['lng'] = sprintf('%.7F', $lng);

        return $this;
    }

    public function zoom(float $zoom): static
    {
        $this->mapProps['zoom'] = $zoom;

        return $this;
    }

    /**
     * `standard`, `satellite`, or `hybrid`. Fully honored by MapKit on
     * iOS; Android's keyless OpenFreeMap tiles have no satellite imagery,
     * so every style renders the standard map there (a future keyed
     * provider can honor it).
     */
    public function mapStyle(string $style): static
    {
        $this->mapProps['style'] = $style;

        return $this;
    }

    public function showUserLocation(bool $value = true): static
    {
        $this->mapProps['show_user_location'] = $value;

        return $this;
    }

    public function interactive(bool $value = true): static
    {
        $this->mapProps['interactive'] = $value;

        return $this;
    }

    /**
     * Whether the camera tracks lat/lng changes across re-renders
     * (default true). With follow disabled the camera is positioned once
     * and then left alone — the user's manual pan/zoom survives new
     * fixes, markers, and polyline updates.
     */
    public function follow(bool $value = true): static
    {
        $this->mapProps['follow'] = $value;

        return $this;
    }

    /**
     * Track to draw. Accepts a JSON string, a list of [lat, lng] pairs,
     * or a list of fix maps with latitude/longitude keys (the shape
     * Geolocation::drainWatch() returns).
     */
    public function polyline(array|string $points): static
    {
        if (is_string($points)) {
            $points = json_decode($points, true) ?: [];
        }

        $pairs = [];
        foreach ($points as $point) {
            if (isset($point['latitude'], $point['longitude'])) {
                $pairs[] = [round((float) $point['latitude'], 6), round((float) $point['longitude'], 6)];
            } elseif (isset($point[0], $point[1])) {
                $pairs[] = [round((float) $point[0], 6), round((float) $point[1], 6)];
            }
        }

        $this->mapProps['polyline'] = json_encode($pairs);

        return $this;
    }

    public function polylineColor(string $color): static
    {
        $this->mapProps['polyline_color'] = $color;

        return $this;
    }

    public function polylineWidth(float $width): static
    {
        $this->mapProps['polyline_width'] = $width;

        return $this;
    }

    protected function defaults(): array
    {
        return [
            'zoom' => 13.0,
            'style' => 'standard',
            'interactive' => true,
            'follow' => true,
            'show_user_location' => false,
            'polyline_color' => '#FC4C02',
            'polyline_width' => 4.0,
        ];
    }

    protected function layoutDefaults(): array
    {
        // A bare <map class="w-full"> should be visible without an
        // explicit height; any height/aspect on the tag overrides this.
        return [
            'height' => 280,
        ];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->mapProps;
    }
}
