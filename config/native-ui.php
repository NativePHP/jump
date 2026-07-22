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
            'on-primary' => '#FFFFFF',

            'secondary' => 'red-500',
            'on-secondary' => '#FFFFFF',

            'surface' => '#FFFFFF',
            'on-surface' => '#111827', // gray-900
            'background' => 'gray-50', // gray-50
            'on-background' => '#111827',

            'surface-variant' => '#F3F4F6', // gray-100
            'on-surface-variant' => '#4B5563', // gray-600

            'outline' => '#E5E7EB', // gray-200

            'destructive' => '#DC2626',
            'on-destructive' => '#FFFFFF',

            'accent' => '#A2FD00', // snow-flurry lime — brand highlight
            'on-accent' => '#16182C', // haiti (dark text on lime)
        ],

        // Dark mode — the site's warm navy-purple, NOT cold slate. haiti is the
        // page, cloud the card surface, torchlight the code/muted layer, with a
        // violet-400 interactive accent and the same snow-flurry brand pop.
        'dark' => [
            'primary' => '#A78BFA', // violet-400 (text-violet-400 on the site)
            'on-primary' => '#1E1B4B', // indigo-950 (dark text on light violet)

            'secondary' => '#8B5CF6', // violet-500
            'on-secondary' => '#FFFFFF',

            'surface' => '#2B2E53', // cloud
            'on-surface' => '#F3F4F6',
            'background' => 'indigo-950', // haiti
            'on-background' => '#F3F4F6',

            'surface-variant' => '#292D3E', // torchlight-surface (code layer)
            'on-surface-variant' => '#A6ACCD', // torchlight-text (muted lavender-gray)

            'outline' => '#383B61', // subtle navy border on haiti/cloud

            'destructive' => '#EF4444',
            'on-destructive' => '#FFFFFF',

            'accent' => '#A2FD00', // snow-flurry lime
            'on-accent' => '#16182C',
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
