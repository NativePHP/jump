<?php

namespace NativePHP\Geolocation\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\IosSymbol;

/**
 * MapMarker — a pin on a `<map>`. Never rendered standalone: the parent
 * map renderer reads marker children as data (skia-canvas pattern) and
 * draws it at lat/lng. `@press` is wired by core onto the node's
 * on_press callback; the map renderer dispatches it on tap.
 *
 * Default look is a colored dot. Give it an icon instead — the standard
 * platform triple, same as buttons/tabs/list items:
 *
 *   <map-marker :lat="$lat" :lng="$lng"
 *               :ios-icon="Ios::LocationFill"
 *               :android-icon="Android::MyLocation"/>
 *
 * (or a shared `icon="..."` name, or plain strings). iOS renders the SF
 * Symbol, Android rasterizes the Material ligature glyph — both tinted
 * with `color`.
 *
 * Lat/lng travel as string props for double precision (see Map).
 */
class MapMarker extends Element
{
    protected string $type = 'map_marker';

    /** @var array<string, mixed> */
    protected array $markerProps = [];

    public static function make(float $lat = 0.0, float $lng = 0.0): static
    {
        return (new static)->lat($lat)->lng($lng);
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['lat'])) {
            $this->lat((float) $attrs['lat']);
        }
        if (isset($attrs['lng'])) {
            $this->lng((float) $attrs['lng']);
        }
        if (isset($attrs['title'])) {
            $this->title($attrs['title']);
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }

        $name = $attrs['icon'] ?? null;
        $ios = $attrs['ios-icon'] ?? $attrs['iosIcon'] ?? null;
        $android = $attrs['android-icon'] ?? $attrs['androidIcon'] ?? null;
        if ($name !== null || $ios !== null || $android !== null) {
            $this->icon($name, $ios, $android);
        }
    }

    /**
     * Platform icon triple (shared name + per-platform enum/string
     * overrides), resolved to the current platform's wire pair by core's
     * IconResolver — identical semantics to Button/Tab/ListItem icons.
     */
    public function icon(
        ?string $name = null,
        IosSymbol|string|null $ios = null,
        AndroidSymbol|string|null $android = null,
    ): static {
        $resolved = IconResolver::resolve($name, $ios, $android);

        if ($resolved['icon'] !== null) {
            $this->markerProps['icon'] = $resolved['icon'];
        }
        if ($resolved['variant'] !== null) {
            $this->markerProps['material_variant'] = $resolved['variant'];
        }

        return $this;
    }

    public function lat(float $lat): static
    {
        $this->markerProps['lat'] = sprintf('%.7F', $lat);

        return $this;
    }

    public function lng(float $lng): static
    {
        $this->markerProps['lng'] = sprintf('%.7F', $lng);

        return $this;
    }

    public function title(string $title): static
    {
        $this->markerProps['title'] = $title;

        return $this;
    }

    public function color(string $color): static
    {
        $this->markerProps['color'] = $color;

        return $this;
    }

    protected function defaults(): array
    {
        return [
            'color' => '#EA4335',
        ];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->markerProps;
    }
}
