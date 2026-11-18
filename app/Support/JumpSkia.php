<?php

namespace App\Support;

use Native\Mobile\Skia\Animation;
use Native\Mobile\Skia\Node;
use Native\Mobile\Skia\Paint;
use Native\Mobile\Skia\Scene;

/**
 * Small, reusable Skia scenes for Jump's UI accents. Each runs on the native
 * animation clock (no per-frame PHP), is transparent so it sits on any theme,
 * and uses Jump red/amber at low weight. Render with
 * `<skia-canvas :scene="App\Support\JumpSkia::loader()" animated .../>`.
 */
class JumpSkia
{
    /**
     * The home hero: an aurora, speed streaks and embers
     * behind the JUMP wordmark — solid brand-red letters with a light that
     * traces each outline in turn (J → U → M → P), then rests. Replaces the
     * plain text wordmark. Transparent, so it sits on light and dark themes.
     */
    public static function hero(): Scene
    {
        $w = JumpWordmark::WIDTH;
        $h = 200.0;

        $aurora = <<<'SKSL'
        uniform float2 res;
        uniform float time;
        half4 main(float2 p) {
            float2 uv = p / res;
            float t = time * 0.5;
            float w1 = sin(uv.x * 5.0 + t + sin(uv.y * 3.0 + t * 0.7));
            float w2 = sin(uv.x * 3.0 - t * 0.8 + uv.y * 4.0);
            float w3 = sin((uv.x + uv.y) * 6.0 + t * 1.3);
            float v = (w1 + w2 + w3) / 3.0 * 0.5 + 0.5;
            half3 red = half3(0.88, 0.16, 0.16);
            half3 amber = half3(1.0, 0.58, 0.2);
            half3 violet = half3(0.52, 0.28, 0.95);
            half3 c = mix(red, amber, half(v));
            c = mix(c, violet, half(smoothstep(0.35, 1.0, w2 * 0.5 + 0.5)) * 0.55);
            float edge = smoothstep(0.0, 0.25, uv.x) * smoothstep(1.0, 0.75, uv.x)
                       * smoothstep(0.0, 0.3, uv.y) * smoothstep(1.0, 0.7, uv.y);
            float a = edge * (0.07 + 0.13 * v);
            return half4(c * half(a), half(a));
        }
        SKSL;

        $scene = Scene::make($w, $h)->add(
            Node::rect(0, 0, $w, $h, Paint::fill()->runtimeShader($aurora, ['res' => [$w, $h]], timeUniform: 'time')),
        );

        // A few speed streaks and embers for depth.
        foreach ([[44, 100, 2.2, 6.2], [150, 120, 2.4, 7.4], [182, 80, 2.0, 6.8]] as [$y, $len, $thick, $secs]) {
            $scene->add(
                Node::rect(0, 0, $len, $thick, Paint::fill()->linearGradient(0, 0, $len, 0, ['#DC262600', '#DC2626D0', '#FFB86BFF']), radius: $thick / 2)
                    ->transform(-$len, $y)
                    ->animate('translateX', Animation::between(-$len, $w + $len, $secs, 'easeIn', 'loop'))
                    ->animate('opacity', Animation::between(0.15, 0.5, $secs / 2, 'easeInOut', 'mirror')),
            );
        }
        for ($i = 0; $i < 6; $i++) {
            $x = 36 + (($i * 113) % 360);
            $y = 140 + (($i * 31) % 50);
            $secs = 3.6 + ($i % 4) * 0.8;
            $scene->add(
                Node::circle(0, 0, 1.6 + ($i % 3) * 0.7, Paint::fill($i % 2 ? '#FFB86B' : '#DC2626'))
                    ->transform($x, $y)
                    ->animate('translateY', Animation::between($y, $y - 56, $secs, 'easeOut', 'loop'))
                    ->animate('opacity', Animation::between(0.55, 0.0, $secs, 'linear', 'loop')),
            );
        }

        return $scene->add(self::wordmark(0, ($h - JumpWordmark::HEIGHT) / 2));
    }

    /**
     * JUMP as outlined letters: solid red body (slightly fattened with a round
     * stroke so thin Audiowide reads chunky) plus a light that traces each
     * outline. One coordinated run per cycle — each letter's light grows out of
     * a point, travels the outline and shrinks into the end, so nothing pops at
     * the loop seam — with the letters starting in turn, then a rest.
     */
    private static function wordmark(float $x, float $y): Node
    {
        $cycle = 8.0;   // seconds per full loop (run + rest)
        $travel = 3.2;  // seconds for the light to go once around a letter
        $lag = 0.6;     // tail trails the head, so the lit segment breathes
        $stagger = 0.65; // delay between letters

        $hold = fn (float $value, float $secs) => Animation::between($value, $value, max($secs, 0.001), 'linear', 'once');
        $run = fn () => Animation::between(0, 1, $travel, 'easeInOut', 'once');

        $nodes = [];
        $traces = [];
        $i = 0;
        foreach (JumpWordmark::PATHS as $d) {
            $delay = $i++ * $stagger;
            $head = Animation::sequence($hold(0, $delay), $run(), $hold(1, $cycle - $delay - $travel))->repeat('loop');
            $tail = Animation::sequence($hold(0, $delay + $lag), $run(), $hold(1, $cycle - $delay - $lag - $travel))->repeat('loop');
            $trace = fn (Paint $paint) => Node::path($d, $paint)
                ->trim(0, 0)
                ->animate('trimStart', $tail)
                ->animate('trimEnd', $head);

            $nodes[] = Node::path($d, Paint::fill()->linearGradient(0, 0, 0, JumpWordmark::HEIGHT, ['#EF4444', '#B91C1C']));
            $nodes[] = Node::path($d, Paint::stroke('#DC2626', 3)->join('round'));
            $traces[] = $trace(Paint::stroke('#FF4D3D', 5)->cap('round')->blur(3)->opacity(0.85));
            $traces[] = $trace(Paint::stroke('#FFE2A8', 1.6)->cap('round'));
        }

        return Node::group([...$nodes, ...$traces])->transform($x, $y);
    }

    /**
     * Quiet backdrop for a docs section card (160×140): a soft red glow behind
     * the centered icon that slowly breathes, plus two faint dots orbiting it.
     * `$seed` (the card's index) shifts speeds and phase so a grid of cards
     * never pulses in sync. Transparent, low alpha — it should barely register.
     */
    public static function sectionCard(int $seed = 0): Scene
    {
        $breath = 3.6 + ($seed % 4) * 0.45;
        $orbit = 16 + ($seed % 5) * 2.5;
        $hold = fn (float $secs) => Animation::between(0, 0, max($secs, 0.001), 'linear', 'once');
        $delay = ($seed % 4) * 0.7;

        $pulse = fn (float $from, float $to) => Animation::sequence(
            Animation::between($from, $from, max($delay, 0.001), 'linear', 'once'),
            Animation::between($from, $to, $breath, 'easeInOut', 'once'),
            Animation::between($to, $from, $breath, 'easeInOut', 'once'),
        )->repeat('loop');

        $scene = Scene::make(160, 140)->add(
            Node::circle(0, 0, 66, Paint::fill()->radialGradient(0, 0, 66, ['#EF444438', '#EF444400']))
                ->transform(80, 54)
                ->animate('scaleX', $pulse(0.85, 1.15))
                ->animate('scaleY', $pulse(0.85, 1.15))
                ->animate('opacity', $pulse(0.55, 1)),
        );

        foreach ([[34, 2.2, 1, '#EF4444', 0.0], [48, 1.6, -1, '#FFB86B', 90.0]] as [$radius, $size, $dir, $color, $offset]) {
            $scene->add(
                Node::group([Node::circle($radius, 0, $size, Paint::fill($color)->opacity(0.55))])
                    ->transform(80, 54, $offset)
                    ->animate('rotation', Animation::between($offset, $offset + 360 * $dir, $orbit, 'linear', 'loop')),
            );
        }

        return $scene;
    }

    /** Spinning red sweep ring with a pulsing core — the loading indicator. */
    public static function loader(): Scene
    {
        $c = 40;

        return Scene::make(80, 80)->add(
            Node::circle($c, $c, 26, Paint::stroke('#DC262633', 4)),
            Node::circle(0, 0, 26, Paint::stroke('#DC2626', 4)->cap('round')->sweepGradient(0, 0, ['#DC262600', '#DC2626', '#FFB86B']))
                ->transform($c, $c)
                ->animate('rotation', Animation::between(0, 360, 1.1, 'linear', 'loop')),
            Node::circle(0, 0, 5, Paint::fill('#DC2626'))
                ->transform($c, $c)
                ->animate('scaleX', Animation::between(0.7, 1.15, 0.8, 'easeInOut', 'mirror'))
                ->animate('scaleY', Animation::between(0.7, 1.15, 0.8, 'easeInOut', 'mirror')),
        );
    }

    /** Expanding white ripples around a dot — "searching the network". For the red pill. */
    public static function radar(): Scene
    {
        $scene = Scene::make(32, 32)->add(Node::circle(16, 16, 3, Paint::fill('#FFFFFF')));

        foreach ([1.7, 2.6] as $secs) {
            $scene->add(
                Node::circle(0, 0, 8, Paint::stroke('#FFFFFF', 1.6))
                    ->transform(16, 16)
                    ->animate('scaleX', Animation::between(0.4, 1.8, $secs, 'easeOut', 'loop'))
                    ->animate('scaleY', Animation::between(0.4, 1.8, $secs, 'easeOut', 'loop'))
                    ->animate('opacity', Animation::between(0.85, 0, $secs, 'easeOut', 'loop')),
            );
        }

        return $scene;
    }

    /** Soft pulsing orb with two orbiting sparks — idle/empty states. */
    public static function idle(): Scene
    {
        $scene = Scene::make(120, 90)->add(
            Node::circle(0, 0, 26, Paint::fill()->radialGradient(0, 0, 26, ['#DC262655', '#DC262600']))
                ->transform(60, 45)
                ->animate('scaleX', Animation::between(0.85, 1.1, 2.4, 'easeInOut', 'mirror'))
                ->animate('scaleY', Animation::between(0.85, 1.1, 2.4, 'easeInOut', 'mirror')),
            Node::circle(60, 45, 4, Paint::fill('#DC2626')),
        );

        foreach ([[26, 6, 4.8, 1, '#FFB86B'], [36, 4.5, 7.2, -1, '#DC2626']] as [$radius, $size, $secs, $dir, $color]) {
            $scene->add(
                Node::group([Node::circle($radius, 0, $size / 2, Paint::fill($color))])
                    ->transform(60, 45)
                    ->animate('rotation', Animation::between(0, 360 * $dir, $secs, 'linear', 'loop')),
            );
        }

        return $scene;
    }
}
