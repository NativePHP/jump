<?php

/**
 * Native UI — Theme Tokens
 *
 * Published via `php artisan vendor:publish --tag=native-ui-config`.
 * Edit to customize your app's visual identity in one place.
 *
 * For dynamic per-tenant theming, use Native\Mobile\UI\Theme::merge([...])
 * from a service provider. Runtime merges deep-merge on top of these values.
 *
 * Decision log: /docs/NATIVE-UI-REWRITE-PLAN.md (D — theme layer)
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Theme
    |---------------------------------------------------------------------------
    |
    | 17 color tokens, 4 radii, 4 font sizes, font family.
    |
    | "on-X" means "color of content placed ON a surface of color X"
    |   — i.e., text/icons on that background.
    |
    | Dark mode is auto-derived from `light` when `dark` is not set. To opt
    | into explicit dark tokens, fill out the `dark` block.
    |
    | The default pairs meet WCAG AA (4.5:1) — if you customize, keep each
    | `on-*` color at 4.5:1 contrast against its background token.
    |
    */

    'theme' => [

        // NativePHP brand palette — ported from nativephp.com. Light mode is
        // white / gray-900 with a red interactive accent; the signature
        // snow-flurry lime (#A2FD00) is reserved for the `accent` brand pop.
        'light' => [
            'primary' => 'red-600',
            'on-primary' => 'white',

            'secondary' => 'red-500',
            'on-secondary' => 'white',

            'surface' => 'white',
            'on-surface' => '#111827', // gray-900
            'background' => 'gray-50', // gray-50
            'on-background' => '#111827',

            'surface-variant' => '#F3F4F6', // gray-100
            'on-surface-variant' => '#4B5563', // gray-600

            'outline' => '#E5E7EB', // gray-200

            'destructive' => '#DC2626',
            'on-destructive' => 'white',

            'accent' => '#A2FD00', // snow-flurry lime — brand highlight
            'on-accent' => '#16182C', // haiti (dark text on lime)
        ],

        // Dark mode — neutral black / ash / charcoal (no blue or purple cast): a
        // near-black page, charcoal cards, a slightly lighter muted layer for
        // code and inputs, thin gray borders, and zinc text. Red-500 stays the
        // interactive accent (one step brighter than light mode) plus the same
        // snow-flurry brand pop.
        'dark' => [
            'primary' => 'red-500',
            'on-primary' => 'white',

            'secondary' => 'red-400',
            'on-secondary' => 'white',

            'surface' => '#151517', // card layer
            'on-surface' => '#F4F4F5', // zinc-100
            'background' => '#09090B', // zinc-950 — near-black page
            'on-background' => '#F4F4F5',

            'surface-variant' => '#202024', // muted / input / code layer
            'on-surface-variant' => '#A1A1AA', // zinc-400

            'outline' => '#2E2E33', // subtle gray border

            'destructive' => '#DC2626',
            'on-destructive' => 'white',

            'accent' => '#A2FD00', // snow-flurry lime — brand highlight
            'on-accent' => '#09090B', // near-black text on lime
        ],

        // Corner radii (points / dp).
        'radius-sm' => 4,
        'radius-md' => 8,
        'radius-lg' => 16,
        'radius-full' => 9999,

        // Font size scale (points / sp).
        'font-sm' => 14,
        'font-md' => 16,
        'font-lg' => 20,
        'font-xl' => 24,

        // 'System' resolves to the platform default (San Francisco on iOS, Roboto on Android).
        // Use a specific family name to load a custom font.
    ],
    'fonts' => [
        'default' => 'Inter-Regular',
        'accent' => 'Audiowide-Regular',
    ],

];
