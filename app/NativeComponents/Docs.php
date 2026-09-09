<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\InteractsWithDiscovery;
use App\NativeComponents\Concerns\SearchesDocs;
use App\Support\DocsIndex;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Fluent;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Facades\Dialog;
use NativePHP\Clipboard\Facades\Clipboard;

/**
 * Docs tab — the NativePHP mobile docs, fetched from the public MCP navigation
 * API (full page content inline), cached 24h. Collapsible section TOC → page.
 */
class Docs extends NativeComponent
{
    use InteractsWithDiscovery;
    use SearchesDocs;

    protected bool $hidesFloatingOverlay = true;

    /**
     * Navigation chrome — renders at the screen edges (tab / nav / side bars),
     * not as inline content, so its snippets show as code only. Overlays
     * (bottom-sheet / modal) are NOT here: they're demoed interactively via the
     * demoState bridge (a trigger button opens a real sheet). See prepareDemo().
     */
    private const NON_INLINE_ELEMENTS = ['bottom-nav', 'top-bar', 'side-nav'];

    /** @var array<int,array{slug:string,name:string,pages:array<int,array<string,mixed>>}> */
    public array $sections = [];

    /** @var array<int,string> expanded section slugs */
    public array $expanded = [];

    public ?array $page = null;

    public bool $loading = true;

    public bool $failed = false;

    /**
     * Live state for the docs' interactive demos (overlays, model bindings,
     * virtual-list windows). Keyed by the snippet's own var name (unique per
     * example), seeded from its `@php` block and mutated by its handlers via
     * {@see setDemo()} / {@see __syncProperty()} / {@see setVirtualWindow()}.
     *
     * @var array<string,mixed>
     */
    public array $demoState = [];

    public function navTitle(): string
    {
        return 'Docs';
    }

    public function mount(): void
    {
        $this->startDiscovery();

        try {
            $this->sections = DocsIndex::sections();
            $this->failed = empty($this->sections);
        } catch (\Throwable) {
            $this->failed = true;
        }
        $this->loading = false;

        // Deep link (https://nativephp.com/docs/mobile/{v}/{section}/{page}) —
        // land directly on the linked page. API page ids mirror the website
        // path after /docs/ ("mobile/4/concepts/deep-links"); a link to an
        // older docs version falls back to the v4 page of the same slug, and
        // an unknown id just shows the TOC with nothing opened.
        if ($section = $this->param('section')) {
            // Nested section slugs ("plugins/core") arrive as two params.
            if ($subsection = $this->param('subsection')) {
                $section .= '/'.$subsection;
            }
            if ($page = $this->param('page')) {
                $tail = $section.'/'.$page;
                $this->open('mobile/'.$this->param('version').'/'.$tail);
                if (! $this->page && $this->param('version') !== '4') {
                    $this->open('mobile/4/'.$tail);
                }
            }
            // A section-only link, or a page slug the docs no longer publish,
            // still lands on the right part of the TOC instead of nothing.
            if (! in_array($section, $this->expanded, true)) {
                $this->expanded[] = $section;
            }
        } elseif ($this->sections !== []) {
            // Plain TOC — open the first section so the landing view isn't a
            // wall of collapsed headers.
            $this->expanded = [$this->sections[0]['slug']];
        }
    }

    public function toggle(string $slug): void
    {
        if (in_array($slug, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$slug]));
        } else {
            $this->expanded[] = $slug;
        }
    }

    public function open(string $id): void
    {
        foreach ($this->sections as $section) {
            foreach ($section['pages'] as $p) {
                if ($p['id'] === $id) {
                    $this->page = $p + ['section' => $section['name']];
                    $this->demoState = [];

                    return;
                }
            }
        }
    }

    /**
     * Footer prev/next — flip the page IN PLACE, no navigation. This used to
     * replace() to the page's deep-link route (to remount scrolled to the
     * top), but a replace() to a child URI of the tab root is misread by the
     * native per-tab coordinator as a PUSH: PHP's stack stays depth-1 while
     * the NavigationStack gains a level with a system back chevron. Tapping
     * that chevron pops natively, PHP's root-screen guard ignores the back
     * event, and the two sides desync — the screen shows a stale cached tree
     * whose callbacks are dead (next/prev stop working). Scroll-to-top now
     * comes from `:native:key="$page['id']"` on the reader's scroll container
     * (fresh native identity per page), and expanding the section keeps the
     * TOC in sync for when the reader closes.
     */
    public function goTo(string $id): void
    {
        $this->open($id);

        if ($this->page) {
            foreach ($this->sections as $section) {
                if ($section['name'] === $this->page['section']
                    && ! in_array($section['slug'], $this->expanded, true)) {
                    $this->expanded[] = $section['slug'];
                }
            }
        }
    }

    /**
     * Pull-to-refresh (both the TOC and the page reader) — refetch the docs
     * corpus from the source (DocsIndex is network-first) and, when a page is
     * open, re-resolve it against the fresh content so edits show up without
     * leaving the page. Keeps the current view when the source is unreachable
     * (sections() falls back to the cached copy, or [] with no cache).
     */
    public function refresh(): void
    {
        try {
            $fresh = DocsIndex::sections();
        } catch (\Throwable) {
            return; // unreachable — keep what's on screen
        }
        if ($fresh === []) {
            return;
        }

        $this->sections = $fresh;
        $this->failed = false;

        if ($this->page) {
            $id = $this->page['id'];
            $this->page = null;
            $this->open($id); // resets demoState when found
            if (! $this->page) {
                // Page vanished upstream (renamed/removed) — back to the TOC.
                $this->demoState = [];
            }
        }
    }

    /**
     * Set a demo var and re-render — the target of the rewritten inline
     * handlers (`@press="$showSheet = true"` → `setDemo('showSheet', true)`).
     * See prepareDemo(). Value defaults to true so a bare toggle also works.
     */
    public function setDemo(string $key, mixed $value = true): void
    {
        $this->demoState[$key] = $value;
    }

    /**
     * Model-binding writes (`native:model="x"` compiles to
     * `_change="__syncProperty('x')"`) land here on user input. Docs' own view
     * declares zero model bindings, so EVERY sync reaching this component is a
     * docs demo — route it into $demoState unconditionally. That both captures
     * the value (the parent implementation silently drops unknown props) and
     * sandboxes Docs' real public props from same-named demo vars. The next
     * frame re-binds it via demoBindings(), so `:value` and `{{ $x }}` echoes
     * pick up the new value.
     */
    public function __syncProperty(string $property, mixed $value): void
    {
        $this->demoState[$property] = $value;
    }

    /**
     * Feedback stub for the docs' method-style handlers (`@press="save"` →
     * `@press="demoCall('save')"`, see prepareDemo). Toasts the method name —
     * the teaching point — instead of silently no-op'ing (or worse, hitting a
     * real Docs method). `$value` absorbs the event payload dispatch() appends
     * for change/submit-type events.
     */
    public function demoCall(string $method, mixed $value = null): void
    {
        Dialog::toast($method.'()'.($value === null ? ' called' : ' called with '.var_export($value, true)));
    }

    /**
     * Window-change target for the virtual-list live example
     * (`on-window-change="setVirtualWindow"` rides through prepareDemo
     * untouched). Stashing the window in $demoState — rather than using
     * HasVirtualListWindow's props — lets demoBindings() feed the snippet's
     * `:from="$virtualWindowFrom"` / `:to="$virtualWindowTo"` next frame.
     */
    public function setVirtualWindow(int $from, int $to): void
    {
        $this->demoState['virtualWindowFrom'] = $from;
        $this->demoState['virtualWindowTo'] = $to;
    }

    /**
     * Copy a code/live block's raw source to the system clipboard. Takes the
     * block INDEX (the raw code can't ride through @press args safely —
     * quotes/newlines); toBlocks() is deterministic for the cached page
     * content, so the index resolves to the same block the user tapped.
     */
    public function copyCode(int $index): void
    {
        if (! $this->page) {
            return;
        }

        $block = $this->toBlocks($this->page['content'])[$index] ?? null;

        if (! is_array($block) || ! isset($block['raw'])) {
            return;
        }

        // Only claim success when the bridge confirms the write — a silent
        // false (e.g. plugin missing from the build) must not toast "Copied".
        Dialog::toast(
            Clipboard::writeText($block['raw'])
                ? 'Copied to clipboard'
                : 'Copy failed — clipboard unavailable'
        );
    }

    public function render(): View
    {
        return view('native.docs', [
            'blocks' => $this->page ? $this->toBlocks($this->page['content']) : [],
            'adjacent' => $this->adjacentPages(),
            // A closure the view calls from a @php block to splice a snippet's
            // live native elements into the page tree at that position.
            'renderLive' => fn (string $snippet) => $this->renderLive($snippet),
        ]);
    }

    /**
     * Prev/next pages for the reader's footer — the corpus flattened in nav
     * order, with neighbors crossing section boundaries. Mirrors the website's
     * footer links (ShowDocumentationController's flattenNavigationPages walk).
     *
     * @return array{prev: ?array{id:string,title:string,section:string}, next: ?array{id:string,title:string,section:string}}
     */
    private function adjacentPages(): array
    {
        if (! $this->page) {
            return ['prev' => null, 'next' => null];
        }

        $flat = [];
        foreach ($this->sections as $section) {
            foreach ($section['pages'] as $p) {
                $flat[] = ['id' => $p['id'], 'title' => $p['title'], 'section' => $section['name']];
            }
        }

        foreach ($flat as $i => $p) {
            if ($p['id'] === $this->page['id']) {
                return ['prev' => $flat[$i - 1] ?? null, 'next' => $flat[$i + 1] ?? null];
            }
        }

        return ['prev' => null, 'next' => null];
    }

    /**
     * Inline-render a doc's <native:*> snippet into the CURRENT element tree
     * (the collector is a shared static stack, so the snippet's elements nest
     * wherever this is called). Side effects only — returns '' so it's safe to
     * invoke from a @php block. Unbound example vars fall back to null; a
     * compile error just renders nothing (the code block still shows below).
     */
    public function renderLive(string $snippet): string
    {
        [$snippet, $seeds] = $this->prepareDemo($snippet);

        set_error_handler(
            fn ($severity, $message) => str_contains($message, 'Undefined variable'),
            E_WARNING
        );
        try {
            Blade::render($snippet, $this->demoBindings($seeds));
        } catch (\Throwable $e) {
            report($e);
        } finally {
            restore_error_handler();
        }

        return '';
    }

    /**
     * Transform a doc snippet for the inline preview and return its seed vars.
     * The rewrites let an example's idiomatic handlers work against Docs (the
     * native runtime only dispatches method calls, and only methods that exist
     * on THIS component):
     *   1. `@php $x = <literal>; @endphp` → seed defaults, then STRIP (leaving
     *      it in would reset the var to its default on every re-render).
     *   2. `@press/@dismiss/@change="$x = <literal>"` → `="setDemo('x', <lit>)"`
     *      so a tap persists into $demoState (survives re-render).
     *   3. Remaining method-style handlers (`@press="save"`) → demoCall()
     *      toasts. Mandatory, not cosmetic: dispatch() silently drops unknown
     *      methods (no feedback), and DOES run a docs handler that happens to
     *      collide with a real Docs method (`toggle`, `open(...)`) against the
     *      reader's own screen.
     *   4. `@navigate` → a demoCall() toast — it would otherwise fire a real
     *      navigation intent into a nonexistent Jump route.
     *   5. List gesture callbacks (on-refresh / on-end-reached /
     *      on-swipe-delete) → demoCall(), so the gesture toasts its method.
     * Also injects `flex-1` on virtualized lists so they fill their tall card.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function prepareDemo(string $raw): array
    {
        $seeds = [];
        $lit = '(true|false|null|-?\d+(?:\.\d+)?|\'[^\']*\'|"[^"]*")';

        // 1) Pull @php initializers → seeds; strip the block.
        $raw = preg_replace_callback('/@php(.*?)@endphp/s', function ($m) use (&$seeds, $lit) {
            if (preg_match_all('/\$(\w+)\s*=\s*'.$lit.'\s*;/', $m[1], $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $seeds[$a[1]] = $this->phpLiteral($a[2]);
                }
            }

            return '';
        }, $raw) ?? $raw;

        // 2) Inline handler assignments → setDemo() calls (a real method).
        $raw = preg_replace(
            '/@(press|longPress|doubleTap|dismiss|change|submit)="\s*\$(\w+)\s*=\s*'.$lit.'\s*"/',
            '@$1="setDemo(\'$2\', $3)"',
            $raw
        ) ?? $raw;

        // 3) Remaining method-style handlers → demoCall('<method>') toasts.
        // Interpolated args ({{ $item->id }}) are dropped — the toast names
        // the method, which is the teaching point.
        $raw = preg_replace_callback(
            '/@(press|longPress|doubleTap|submit|dismiss|change)="\s*(?!setDemo\()(\w+)[^"]*"/',
            fn ($m) => '@'.$m[1].'="demoCall(\''.$m[2].'\')"',
            $raw
        ) ?? $raw;

        // 4) `@navigate` (any modifiers/value) → a demoCall toast. AFTER 3),
        // or the produced @press="demoCall(…)" would itself get rewritten.
        $raw = preg_replace(
            '/@navigate(?:\.[\w.]+)?(?:="[^"]*")?/',
            '@press="demoCall(\'navigate\')"',
            $raw
        ) ?? $raw;

        // 5) List gesture callbacks → demoCall, so pull-to-refresh /
        // infinite-scroll / swipe-delete demos toast at the gesture moment.
        $raw = preg_replace_callback(
            '/\bon-(refresh|end-reached|swipe-delete|leading-change|trailing-change)="\s*(\w+)[^"]*"/',
            fn ($m) => 'on-'.$m[1].'="demoCall(\''.$m[2].'\')"',
            $raw
        ) ?? $raw;

        // 6) Virtualized lists fill their tall card (see $isTall). `(?![\w-])`
        // so it doesn't match inside `<native:list-item>`.
        $raw = preg_replace(
            '/<native:((?:virtual-)?list)(?![\w-])([^>]*\bclass=")/',
            '<native:$1$2flex-1 ',
            $raw
        ) ?? $raw;
        $raw = preg_replace(
            '/<native:((?:virtual-)?list)(?![\w-])(?![^>]*\bclass=)/',
            '<native:$1 class="flex-1"',
            $raw
        ) ?? $raw;

        return [$raw, $seeds];
    }

    /** Resolve a matched PHP literal token to its value (for demo seeds). */
    private function phpLiteral(string $token): mixed
    {
        return match (true) {
            $token === 'true' => true,
            $token === 'false' => false,
            $token === 'null' => null,
            is_numeric($token) => $token + 0,
            default => substr($token, 1, -1), // strip surrounding quotes
        };
    }

    /**
     * Bindings for a demo render: fixtures, then the snippet's seed defaults,
     * then live $demoState (so a tapped-open sheet stays open across renders).
     *
     * @param  array<string,mixed>  $seeds
     * @return array<string,mixed>
     */
    private function demoBindings(array $seeds): array
    {
        return array_merge($this->sampleBindings(), $seeds, $this->demoState);
    }

    /**
     * Fixture bindings for the docs' live examples — the demo data a snippet's
     * unbound vars resolve against. Collections are Fluent items so both
     * `$item->name` and `$item['name']` (each used by the docs) work. Built
     * from a scan of every `<native:*>` fenced block in the current docs;
     * snippets needing anything else (app models like `Contact::`, `$this`)
     * fail the canRenderLive() preflight and fall back to a plain code block.
     */
    private function sampleBindings(): array
    {
        $f = fn (array $rows) => array_map(fn ($r) => new Fluent($r), $rows);

        return [
            // Scalars
            'unreadCount' => 3,
            'cartItems' => 2,
            'count' => 3,
            'notifications' => 5,
            'difficulty' => null,
            'tiers' => ['Basic', 'Pro', 'Team'],
            'agreed' => false,
            'enabled' => true,
            'score' => 87,
            'total' => 200,
            'darkMode' => false,
            'processing' => false,
            'showDetails' => false,
            'pulsing' => true,
            'location' => 'San Francisco',
            'title' => 'Sample title',
            'name' => 'Jump',
            'index' => 0,
            'previewUrl' => 'https://picsum.photos/seed/nativephp/600/400',
            'cadence' => 'weekly',
            'shippingMethod' => 'standard',
            'countries' => ['United States', 'Canada', 'United Kingdom'],
            'virtualWindowFrom' => 0,
            'virtualWindowTo' => 20,

            // Menus (menus.md) — NavAction arrays can't ride the @php seed
            // convention (scalar literals only), so the docs' `$menu` /
            // `$rowMenu` bind here. Shapes mirror the page's own php fence;
            // press handlers stay idiomatic and toast via demoCall().
            'menu' => [
                NavAction::make('export_pdf')->icon('doc')->label('Export as PDF')->press('exportPdf'),
                NavAction::make('export_csv')->icon('tablecells')->label('Export as CSV')->press('exportCsv'),
                NavAction::divider(),
                NavAction::make('delete')->icon('trash')->label('Delete')->press('delete')->destructive(),
            ],
            'rowMenu' => [
                NavAction::make('pin')->icon('pin')->label('Pin')->press('pin'),
                NavAction::make('mute')->icon('bell.slash')->label('Mute')->press('mute'),
                NavAction::divider(),
                NavAction::make('archive')->icon('archivebox')->label('Archive')->press('archive'),
            ],

            // Collections (shapes mirror what the docs access on each item).
            // Row counts are deliberately generous so scroll / pull-to-refresh /
            // infinite-scroll demos have something to actually scroll.
            'items' => $f([
                ['id' => 1, 'name' => 'Design review', 'description' => 'Look over the new palette', 'subtitle' => 'Today'],
                ['id' => 2, 'name' => 'Ship build 42', 'description' => 'Upload to TestFlight', 'subtitle' => 'Tomorrow'],
                ['id' => 3, 'name' => 'Write changelog', 'description' => 'v1.4 release notes', 'subtitle' => 'Friday'],
                ['id' => 4, 'name' => 'Fix dark mode', 'description' => 'Theme tokens on the settings screen', 'subtitle' => 'Friday'],
                ['id' => 5, 'name' => 'Record demo video', 'description' => 'Two minutes, straight to the point', 'subtitle' => 'Saturday'],
                ['id' => 6, 'name' => 'Update screenshots', 'description' => 'App Store + Play Store sets', 'subtitle' => 'Sunday'],
                ['id' => 7, 'name' => 'Triage issues', 'description' => 'Close the stale ones', 'subtitle' => 'Monday'],
                ['id' => 8, 'name' => 'Plan v1.5', 'description' => 'Rough milestones only', 'subtitle' => 'Monday'],
                ['id' => 9, 'name' => 'Refactor onboarding', 'description' => 'Three screens down to one', 'subtitle' => 'Tuesday'],
                ['id' => 10, 'name' => 'Push notifications', 'description' => 'Wire up the new plugin', 'subtitle' => 'Wednesday'],
                ['id' => 11, 'name' => 'Beta feedback pass', 'description' => 'Reply to TestFlight reviews', 'subtitle' => 'Thursday'],
                ['id' => 12, 'name' => 'Tag the release', 'description' => 'And breathe', 'subtitle' => 'Next Friday'],
            ]),
            'posts' => $f([
                ['id' => 1, 'title' => 'Hello, NativePHP', 'excerpt' => 'Build native apps with the Laravel you know.'],
                ['id' => 2, 'title' => 'Going Edge', 'excerpt' => 'Server-driven native UI, rendered on device.'],
                ['id' => 3, 'title' => 'Jump In', 'excerpt' => 'Scan a QR code, run your app on your phone.'],
                ['id' => 4, 'title' => 'Blade, Natively', 'excerpt' => 'Your views compile to SwiftUI and Compose.'],
                ['id' => 5, 'title' => 'Hot Reload', 'excerpt' => 'Save a file, watch the simulator catch up.'],
                ['id' => 6, 'title' => 'Ship It', 'excerpt' => 'One codebase, both app stores.'],
                ['id' => 7, 'title' => 'Deep Links', 'excerpt' => 'From a URL straight to a screen.'],
                ['id' => 8, 'title' => 'Offline First', 'excerpt' => 'SQLite is right there in your app.'],
                ['id' => 9, 'title' => 'Secrets Kept', 'excerpt' => 'Keychain and Keystore behind one facade.'],
                ['id' => 10, 'title' => 'Native Feel', 'excerpt' => 'Real platform components, not lookalikes.'],
            ]),
            'contacts' => $f([
                ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=5', 'initials' => 'AL'],
                ['id' => 2, 'name' => 'Grace Hopper', 'email' => 'grace@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=47', 'initials' => 'GH'],
                ['id' => 3, 'name' => 'Alan Turing', 'email' => 'alan@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=12', 'initials' => 'AT'],
                ['id' => 4, 'name' => 'Katherine Johnson', 'email' => 'katherine@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=44', 'initials' => 'KJ'],
                ['id' => 5, 'name' => 'Edsger Dijkstra', 'email' => 'edsger@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=13', 'initials' => 'ED'],
                ['id' => 6, 'name' => 'Margaret Hamilton', 'email' => 'margaret@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=32', 'initials' => 'MH'],
                ['id' => 7, 'name' => 'Donald Knuth', 'email' => 'donald@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=53', 'initials' => 'DK'],
                ['id' => 8, 'name' => 'Radia Perlman', 'email' => 'radia@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=26', 'initials' => 'RP'],
                ['id' => 9, 'name' => 'Barbara Liskov', 'email' => 'barbara@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=49', 'initials' => 'BL'],
                ['id' => 10, 'name' => 'Dennis Ritchie', 'email' => 'dennis@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=59', 'initials' => 'DR'],
                ['id' => 11, 'name' => 'Frances Allen', 'email' => 'frances@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=38', 'initials' => 'FA'],
                ['id' => 12, 'name' => 'Tim Berners-Lee', 'email' => 'tim@example.com', 'avatar' => 'https://i.pravatar.cc/150?img=61', 'initials' => 'TB'],
            ]),
            'features' => $f([
                ['color' => '#4F46E5', 'title' => 'Native UI', 'subtitle' => 'SwiftUI & Compose renderers'],
                ['color' => '#10B981', 'title' => 'Live reload', 'subtitle' => 'Save and see it instantly'],
            ]),
            'tasks' => $f([
                ['id' => 1, 'title' => 'Book flights', 'due' => 'Fri', 'done' => true],
                ['id' => 2, 'title' => 'Reserve hotel', 'due' => 'Sat', 'done' => false],
                ['id' => 3, 'title' => 'Pack chargers', 'due' => 'Sun', 'done' => false],
                ['id' => 4, 'title' => 'Print boarding passes', 'due' => 'Mon', 'done' => false],
                ['id' => 5, 'title' => 'Arrange cat sitter', 'due' => 'Tue', 'done' => true],
                ['id' => 6, 'title' => 'Set out-of-office', 'due' => 'Wed', 'done' => false],
            ]),
            'conversations' => $f([
                ['id' => 1, 'name' => 'Simon', 'preview' => 'The new build looks great!'],
                ['id' => 2, 'name' => 'Shane', 'preview' => 'Shipping the docs update now.'],
                ['id' => 3, 'name' => 'Ana', 'preview' => 'Can you review my PR?'],
                ['id' => 4, 'name' => 'Dev Team', 'preview' => 'Standup moved to 10:30.'],
                ['id' => 5, 'name' => 'Caleb', 'preview' => 'The demo went really well.'],
                ['id' => 6, 'name' => 'Marco', 'preview' => 'Lunch on Thursday?'],
            ]),
            'categories' => $f([
                ['name' => 'Getting Started'],
                ['name' => 'Components'],
                ['name' => 'Plugins'],
            ]),
            'messages' => $f([
                ['id' => 1, 'body' => 'Hey! How is the release going?'],
                ['id' => 2, 'body' => 'Almost there — docs left.'],
                ['id' => 3, 'body' => 'Nice — previews working yet?'],
                ['id' => 4, 'body' => 'Live examples render inline now.'],
                ['id' => 5, 'body' => "That's huge. Android too?"],
                ['id' => 6, 'body' => 'Yep, Compose side is done.'],
                ['id' => 7, 'body' => 'Ship it before the weekend?'],
                ['id' => 8, 'body' => "That's the plan."],
            ]),
            'emails' => $f([
                ['id' => 1, 'subject' => 'Your build finished'],
                ['id' => 2, 'subject' => 'Welcome to Bifrost'],
                ['id' => 3, 'subject' => 'Weekly digest: 5 new components'],
                ['id' => 4, 'subject' => 'Your QR code expires soon'],
                ['id' => 5, 'subject' => 'v4 docs are live'],
                ['id' => 6, 'subject' => 'Simulator tips and tricks'],
                ['id' => 7, 'subject' => 'Receipt for your subscription'],
                ['id' => 8, 'subject' => 'Security alert: new sign-in'],
            ]),
        ];
    }

    /** @var array<string,bool> per-snippet preflight verdicts (md5 → renderable) */
    private static array $liveVerdicts = [];

    /**
     * Preflight a live snippet OUTSIDE the page render: try the real render at
     * a point where the collector is idle, then restore its state (discarding
     * the preflight elements). A snippet that throws mid-splice would leave
     * unclosed elements on the shared collector stack and the REST OF THE PAGE
     * would nest inside them — scrambling the whole tree. Anything that fails
     * here (unknown vars, app-only classes like `Contact::`) renders as a
     * plain code block instead of a live preview.
     *
     * Only call when the collector is idle (toBlocks time) — restoring cannot
     * undo child-mutations on a mid-render parent element.
     */
    /**
     * Whether a snippet renders acceptably in a small inline preview card.
     * False for:
     *  · navigation chrome (tab / nav / side bars) — see NON_INLINE_ELEMENTS;
     *  · full-screen root layouts, flagged by `safe-area` (a whole-screen
     *    example that can't be shown in a card). NOT plain `h-full` — that's
     *    legitimately used on inner fill elements (e.g. an image inside a
     *    bottom-sheet), which preview fine.
     */
    private function previewableInline(string $raw): bool
    {
        if (preg_match('/<native:('.implode('|', self::NON_INLINE_ELEMENTS).')(?![\w-])/', $raw)) {
            return false;
        }

        // Overlay demos are previewable via the demoState bridge (trigger button
        // + a sheet/modal that opens on tap) — even when the sheet/modal BODY
        // uses safe-area/h-full for a full-screen surface. The safe-area block
        // below is only for non-overlay, full-screen ROOT layouts.
        if (preg_match('/<native:(bottom-sheet|modal)(?![\w-])/', $raw)) {
            return true;
        }

        return ! preg_match('/\bsafe-area\b/', $raw);
    }

    private function canRenderLive(string $snippet): bool
    {
        $key = md5($snippet);
        if (isset(self::$liveVerdicts[$key])) {
            return self::$liveVerdicts[$key];
        }

        // Preflight through the SAME transform + bindings as renderLive, so the
        // verdict matches what will actually render.
        [$prepared, $seeds] = $this->prepareDemo($snippet);

        $state = $this->collectorState();
        set_error_handler(
            fn ($severity, $message) => str_contains($message, 'Undefined variable'),
            E_WARNING
        );
        try {
            Blade::render($prepared, $this->demoBindings($seeds));

            return self::$liveVerdicts[$key] = true;
        } catch (\Throwable) {
            return self::$liveVerdicts[$key] = false;
        } finally {
            restore_error_handler();
            $this->collectorState($state);
        }
    }

    /**
     * Snapshot (no args) or restore (with snapshot) the collector's static
     * state. It exposes no checkpoint API, so reflection it is.
     */
    private function collectorState(?array $restore = null): array
    {
        $rc = new \ReflectionClass(NativeElementCollector::class);
        $out = [];
        foreach (['stack', 'roots', 'textFrames', 'pollIntervals'] as $name) {
            $prop = $rc->getProperty($name);
            $prop->setAccessible(true);
            if ($restore !== null) {
                $prop->setValue(null, $restore[$name]);
            } else {
                $out[$name] = $prop->getValue();
            }
        }

        return $out;
    }

    /**
     * Small markdown → block list for rendering. Strips HTML/Blade/@directives
     * and echoes; classifies each line as heading / bullet / code fence / text.
     *
     * @return array<int,array{type:string,text:string}>
     */
    private function toBlocks(string $md): array
    {
        $blocks = [];
        $inCode = false;
        $code = [];
        $fenceLang = '';
        $fenceStatic = false;
        $para = [];
        $quote = [];

        // Consecutive non-blank text lines are ONE paragraph (markdown joins
        // hard-wrapped lines) — buffer them and flush on a blank line or any
        // block-level element, joining with a space.
        $flushPara = function () use (&$para, &$blocks) {
            if ($para) {
                $text = implode(' ', $para);
                // Inline code → prose/code runs composed into one wrapping
                // <text> (native AttributedString). Plain prose stays a leaf.
                $blocks[] = $this->hasInlineCode($text)
                    ? ['type' => 'p', 'segments' => $this->inlineSegments($text)]
                    : ['type' => 'p', 'text' => $this->inline($text)];
                $para = [];
            }
        };

        // Consecutive `> …` lines are ONE callout. The variant comes from a
        // leading marker in either GitHub alert syntax — `[!NOTE]` / `[!TIP]` /
        // `[!IMPORTANT]` / `[!WARNING]` / `[!CAUTION]` — or Laravel-docs style
        // `{note}` / `{tip}` / … . The marker is stripped from the body.
        $flushQuote = function () use (&$quote, &$blocks) {
            if (! $quote) {
                return;
            }
            $text = trim(implode(' ', $quote));
            $variant = 'note';
            if (preg_match('/^\[!(note|tip|important|warning|caution)\]\s*/i', $text, $m)
                || preg_match('/^\{(note|tip|important|warning|caution|danger)\}\s*/i', $text, $m)) {
                $variant = strtolower($m[1]);
                $text = trim(substr($text, strlen($m[0])));
            }
            if ($variant === 'danger') {
                $variant = 'caution';
            }
            $blocks[] = ['type' => 'callout', 'variant' => $variant, 'text' => $this->inline($text)];
            $quote = [];
        };

        // Consecutive `| … |` lines are ONE markdown table: first row is the
        // header, `|---|---|` alignment rows are skipped, the rest are data
        // rows. Cells render like list items (inline-code cells → runs).
        $table = [];
        $flushTable = function () use (&$table, &$blocks) {
            if (! $table) {
                return;
            }
            $parseRow = function (string $line): array {
                $cells = array_map('trim', explode('|', trim(trim($line), '|')));

                return array_map(fn ($c) => $this->hasInlineCode($c)
                    ? ['segments' => $this->inlineSegments($c)]
                    : ['text' => $this->inline($c)], $cells);
            };
            $header = null;
            $rows = [];
            foreach ($table as $line) {
                if (preg_match('/^[\s|:\-]+$/', $line)) {
                    continue; // |---|:---| alignment separator
                }
                if ($header === null) {
                    $header = $parseRow($line);
                } else {
                    $rows[] = $parseRow($line);
                }
            }
            if ($header) {
                $blocks[] = ['type' => 'table', 'header' => $header, 'rows' => $rows];
            }
            $table = [];
        };

        foreach (preg_split('/\r?\n/', $md) as $line) {
            $t = trim($line);

            if (str_starts_with($t, '```')) {
                $flushPara();
                $flushQuote();
                $flushTable();
                if ($inCode) {
                    // Closing fence — a blade/plain block containing <native:*>
                    // is a LIVE example (rendered inline); anything else is code.
                    $raw = rtrim(implode("\n", $code));
                    $isLive = in_array($fenceLang, ['blade', ''], true)
                        // Author opt-out: `` ```blade static `` (or `no-preview`)
                        // in the docs source keeps a conceptual snippet code-only
                        // even though it contains <native:*>. The marker rides
                        // through verbatim from the markdown (the website's
                        // Torchlight highlighter reads only the first word).
                        && ! $fenceStatic
                        && str_contains($raw, '<native:')
                        // Not everything previews well in a small inline card
                        // (overlays, full-screen layouts) — those fall back to
                        // a plain code block. See previewableInline().
                        && $this->previewableInline($raw)
                        // Preflight: only splice snippets that render cleanly
                        // against the fixture bindings — a failing snippet
                        // would corrupt the page tree (see canRenderLive).
                        && $this->canRenderLive($raw);
                    // Virtualized lists (List/LazyColumn) collapse to ~10pt when
                    // measured unbounded — give them a fixed-height card and let
                    // renderLive() make the list fill it (flex-1). Root
                    // scroll-view snippets get the same bounded card: unbounded,
                    // the preview just grows to content height and nothing
                    // scrolls. Everything else previews at its natural height.
                    $isTall = $isLive && (bool) preg_match(
                        '/<native:(virtual-)?list(?![\w-])|^\s*<native:scroll-view(?![\w-])/',
                        $raw
                    );
                    $blocks[] = $isLive
                        ? ['type' => 'live', 'snippet' => $raw, 'raw' => $raw, 'tall' => $isTall, 'lines' => $this->highlight($raw)]
                        : ['type' => 'code', 'raw' => $raw, 'lines' => $this->highlight($raw)];
                    $code = [];
                    $fenceLang = '';
                    $fenceStatic = false;
                } else {
                    // Opening fence — first word is the language; any remaining
                    // words are attributes (e.g. `static` / `no-preview`, the
                    // live-preview opt-out). A bare ``` fence yields ''.
                    $info = preg_split('/\s+/', strtolower(trim(substr($t, 3))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $fenceLang = $info[0] ?? '';
                    $fenceStatic = (bool) array_intersect($info, ['static', 'no-preview']);
                }
                $inCode = ! $inCode;

                continue;
            }
            if ($inCode) {
                $code[] = $line;

                continue;
            }

            // Blockquote / callout — accumulate; a non-quote line ends it.
            if (str_starts_with($t, '>')) {
                $flushPara();
                $quote[] = ltrim(preg_replace('/^>\s?/', '', $t));

                continue;
            }
            $flushQuote();

            // Table row — accumulate; any non-`|` line ends the table.
            if (str_starts_with($t, '|')) {
                $flushPara();
                $table[] = $t;

                continue;
            }
            $flushTable();

            if ($t === '' || preg_match('/^(<|@|\{\{)/', $t) || str_starts_with($t, '![')) {
                $flushPara();

                continue;
            }

            if (preg_match('/^(#{2,6})\s+(.*)/', $t, $m)) {
                $flushPara();
                $blocks[] = ['type' => 'h'.min(strlen($m[1]), 3), 'text' => $this->inline($m[2])];
            } elseif (preg_match('/^[-*]\s+(.*)/', $t, $m)) {
                $flushPara();
                $blocks[] = $this->hasInlineCode($m[1])
                    ? ['type' => 'li', 'segments' => $this->inlineSegments($m[1])]
                    : ['type' => 'li', 'text' => $this->inline($m[1])];
            } elseif (preg_match('/^\d+\.\s+(.*)/', $t, $m)) {
                $flushPara();
                $blocks[] = $this->hasInlineCode($m[1])
                    ? ['type' => 'li', 'segments' => $this->inlineSegments($m[1])]
                    : ['type' => 'li', 'text' => $this->inline($m[1])];
            } else {
                $para[] = $t;
            }
        }
        $flushPara();
        $flushQuote();
        $flushTable();
        if ($inCode && $code) {
            $raw = rtrim(implode("\n", $code));
            $blocks[] = ['type' => 'code', 'raw' => $raw, 'lines' => $this->highlight($raw)];
        }

        return $blocks;
    }

    /**
     * Tokenize code for syntax highlighting, GROUPED BY LINE. Each line becomes
     * a list of {t: text, c: class} spans that the view lays out inline in a
     * <row>; real newlines are the only line breaks. (Nested <text> runs in a
     * single <text> stack vertically in this renderer, so per-line rows are the
     * correct structure.)
     *
     * @return array<int,array<int,array{t:string,c:string}>>
     */
    private function highlight(string $code): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $code) as $line) {
            $spans = $this->highlightLine($line);
            // Blank line → a single space so the row keeps its height.
            $lines[] = $spans ?: [['t' => ' ', 'c' => 'text-slate-300']];
        }

        return $lines;
    }

    /**
     * Tokenize a single line (no newlines). Strings match first so `//` inside
     * them isn't treated as a comment; the plain gaps between matches are kept
     * as their own spans, so joining all `t` reproduces the line exactly.
     *
     * @return array<int,array{t:string,c:string}>
     */
    private function highlightLine(string $line): array
    {
        $pattern = '/('
            .'"[^"]*"|\'[^\']*\''       // strings (first — protect // inside)
            .'|\/\/.*$'                // // line comment
            .'|\$[a-zA-Z_]\w*'          // php variable
            .'|<\/?[\w:.-]+'           // tag open:  <native:button   </column
            .'|\/?>'                   // tag close:  >   />
            .'|[@:]?[\w-]+='            // attribute:  label=  @press=  :size=
            .')/';

        $parts = preg_split($pattern, $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        $spans = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === null) {
                continue;
            }
            $class = match (true) {
                $p[0] === '"' || $p[0] === "'" => 'text-emerald-400',
                str_starts_with($p, '//') => 'text-slate-500',
                $p[0] === '$' => 'text-amber-400',
                str_starts_with($p, '<') || $p === '>' || $p === '/>' => 'text-sky-400',
                str_ends_with($p, '=') => 'text-violet-300',
                default => 'text-slate-300',
            };
            // Native <text> trims leading/trailing whitespace, which would jam
            // adjacent spans together and drop indentation. Use non-breaking
            // spaces (and a tab → 4) so every space survives, monospace-aligned.
            $text = str_replace([' ', "\t"], ["\u{00A0}", str_repeat("\u{00A0}", 4)], $p);
            $spans[] = ['t' => $text, 'c' => $class];
        }

        return $spans;
    }

    /** Strip inline markdown/HTML down to readable plain text. */
    private function inline(string $s): string
    {
        // Protect inline code first — it often IS a tag (e.g. `<native:icon />`)
        // and must survive the HTML strip below, or the sentence loses its
        // subject ("… is a self-closing element").
        $codes = [];
        $s = preg_replace_callback('/`([^`]*)`/', function ($m) use (&$codes) {
            $codes[] = $m[1];

            return "\x00".(count($codes) - 1)."\x00";
        }, $s);

        $s = $this->stripInlineMarkdown($s);

        // Restore protected inline code verbatim.
        $s = preg_replace_callback('/\x00(\d+)\x00/', fn ($m) => $codes[(int) $m[1]] ?? '', $s);

        return trim((string) $s);
    }

    /** Strip bold/italic/link/HTML markup from prose (no inline-code handling). */
    private function stripInlineMarkdown(string $s): string
    {
        $s = preg_replace('/\*\*([^*]*)\*\*/', '$1', $s);
        $s = preg_replace('/\*([^*]*)\*/', '$1', $s);
        $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s);

        return (string) preg_replace('/<[^>]+>/', '', $s);
    }

    /**
     * Split a line into alternating prose / inline-code runs. Each run becomes
     * a child <text> inside one parent <text>; the native renderer composes
     * them into a single wrapping AttributedString (per-run font/color/bg), so
     * prose wraps normally and code runs carry the mono chip styling.
     *
     * Edge whitespace on prose runs is deliberately preserved ("SwiftUI ",
     * " / ") — the run renderer keeps meaningful edge spaces and only collapses
     * internal runs of whitespace, so real (breakable) spaces sit around chips.
     *
     * @return array<int,array{t:string,code:bool}>
     */
    private function inlineSegments(string $s): array
    {
        // Unwrap markdown links BEFORE splitting on backticks — a link whose
        // text is inline code (`[`<native:stack>`](stack)`) would otherwise be
        // split across segments and leave literal "[" / "](stack)" fragments
        // (stripInlineMarkdown only sees the pieces, never the full pattern).
        $s = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s);

        $segments = [];
        $pushProse = function (string $prose) use (&$segments) {
            // Keep edges; strip markdown/HTML markup only.
            $prose = $this->stripInlineMarkdown($prose);
            if ($prose !== '') {
                $segments[] = ['t' => $prose, 'code' => false];
            }
        };

        $offset = 0;
        if (preg_match_all('/`([^`]*)`/', $s, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $whole) {
                $pushProse(substr($s, $offset, $whole[1] - $offset));
                $segments[] = ['t' => $matches[1][$i][0], 'code' => true];
                $offset = $whole[1] + strlen($whole[0]);
            }
        }
        $pushProse(substr($s, $offset));

        return $segments;
    }

    /** True when a line has inline `code` worth rendering as chips. */
    private function hasInlineCode(string $s): bool
    {
        return (bool) preg_match('/`[^`]+`/', $s);
    }
}
