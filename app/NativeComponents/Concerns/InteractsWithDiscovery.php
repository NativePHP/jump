<?php

namespace App\NativeComponents\Concerns;

use App\NativeComponents\Layouts\JumpTabsLayout;
use App\Support\DiscoveredServers;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Attributes\On;
use NativePHP\Discovery\Events\ServerFound;
use NativePHP\Discovery\Events\ServerLost;
use NativePHP\Discovery\Facades\Discovery;
use Native\Mobile\UI\Theme;

/**
 * Feeds the app-wide {@see DiscoveredServers} store from whatever tab is
 * currently active, and owns the "servers nearby" pill's local UI state.
 *
 * Native discovery events are delivered only to the mounted component, so every
 * tab (`use`s this trait) listens and writes into the shared store; the
 * floating pill — rendered app-wide from {@see JumpTabsLayout}
 * — then reads the store on any tab. `$showServers` is per-screen (only one tab
 * is active at a time) and drives the server-list bottom sheet.
 */
trait InteractsWithDiscovery
{
    /** Whether the discovered-servers bottom sheet is open on this screen. */
    public bool $showServers = false;

    /**
     * Whether the full-screen "connecting…" cover is up.
     *
     * It exists for more than politeness: the remote app pushes its OWN theme
     * (colors, typography) as it boots, which lands while Jump's UI is still on
     * screen. Its font aliases name files that don't exist in Jump's bundle, so
     * home visibly re-renders in fallback fonts for the second or two before
     * the remote tree takes over. Covering that window hides the shift — and
     * the cover itself is styled with fixed colors and no font tokens, so the
     * incoming theme can't move it either.
     */
    public bool $connecting = false;

    /** Advertised name of the server being connected to, for the cover's copy. */
    public string $connectingTo = '';

    /**
     * Start browsing the LAN. Call from each tab's `mount()` — NOT from a
     * service provider: the native browser reports already-running servers in
     * an immediate burst right after `start()`, and `#[On(ServerFound)]`
     * listeners are only registered as a component mounts. Starting at boot
     * (before any listener exists) drops that first burst, so servers already
     * up when the app opens never present. Idempotent, so re-calling it as
     * later tabs mount is a harmless no-op.
     */
    public function startDiscovery(): void
    {
        Discovery::start();
    }

    /**
     * Fired by the shell when a remote Jump session ends (server died or the
     * escape hatch) and the local home runloop resumes. While the session was
     * live, ALL native events — including ServerLost for the very server that
     * died — were forked to the remote app, so the store may hold phantom
     * servers the native browser has already reported lost (it emits deltas
     * exactly once and won't repeat them). Flush and re-browse: stop() clears
     * the native emitted-set, start() re-reports every live server fresh.
     */
    #[On('__jumpResume')]
    public function jumpSessionResumed(): void
    {
        $this->showServers = false;
        // Covers both exits: a clean 3-finger swipe, and a connection that
        // died or never produced a tree (the relay routes an abandoned
        // reconnect through the same escape-hatch path). Either way home is
        // about to repaint, so the cover must not survive into it.
        $this->connecting = false;
        $this->connectingTo = '';
        app(DiscoveredServers::class)->flush();
        Discovery::stop();
        Discovery::start();

        // The remote app pushed ITS theme (colors, typography) into the
        // native theme store via NativeUI.Theme.Set during the session —
        // fonts it registered don't exist in Jump's bundle, so home falls
        // back to system fonts. Re-push Jump's own tokens on resume.
        Theme::pushToNative();
    }

    #[On(ServerFound::class)]
    public function serverFound(string $host, string $port, string $name = 'Jump server'): void
    {
        app(DiscoveredServers::class)->put($host, $port, $name);
    }

    #[On(ServerLost::class)]
    public function serverLost(string $host, string $port): void
    {
        $store = app(DiscoveredServers::class);
        $store->forget($host, $port);

        if ($store->isEmpty()) {
            $this->showServers = false;
        }
    }

    /** Toggle the server-list sheet (bound to the floating pill's tap). */
    public function toggleServers(): void
    {
        $this->showServers = ! $this->showServers;
    }

    /** Close the server-list sheet (bound to the sheet's @dismiss). */
    public function closeServers(): void
    {
        $this->showServers = false;
    }

    /** Whether the "how to exit" coaching sheet is open. */
    public bool $showExitHint = false;

    /** "Don't show this again" checkbox state on the coaching sheet. */
    public bool $dontShowExitHintAgain = false;

    /** Connection stashed while the exit-hint sheet is up. */
    public string $pendingHost = '';

    public string $pendingPort = '';

    /** Cache key (database store → device SQLite, survives restarts). */
    private const EXIT_HINT_SEEN_KEY = 'jump.exit-hint-seen';

    /**
     * Connect to a dev server and dismiss the sheet. Interject the
     * escape-hatch coaching sheet first — once connected, the remote app
     * takes over the whole screen and the 3-finger swipe is the only way
     * back, so it must be taught BEFORE the handoff. Shown on every connect
     * until the user opts out via the "don't show this again" checkbox.
     */
    public function connect(string $host, string $port): void
    {
        $this->showServers = false;

        if (! Cache::get(self::EXIT_HINT_SEEN_KEY)) {
            $this->pendingHost = $host;
            $this->pendingPort = $port;
            $this->showExitHint = true;

            return;
        }

        $this->raiseConnectingCover($host, $port);

        Discovery::connect($host, $port);
    }

    /**
     * Raise the cover BEFORE the bridge call. Discovery::connect() returns
     * immediately (the native side dials on the main queue), so this render
     * lands first and the remote tree replaces it whenever it's ready.
     */
    private function raiseConnectingCover(string $host, string $port): void
    {
        $this->connectingTo = app(DiscoveredServers::class)->nameFor($host, $port);
        $this->connecting = true;
    }

    /** The coaching sheet's "don't show this again" checkbox. */
    public function setDontShowExitHintAgain(bool $value): void
    {
        $this->dontShowExitHintAgain = $value;
    }

    /**
     * "Got it" on the coaching sheet: connect, and only suppress future
     * sheets if the user explicitly opted out via the checkbox.
     */
    public function connectPending(): void
    {
        if ($this->dontShowExitHintAgain) {
            Cache::forever(self::EXIT_HINT_SEEN_KEY, true);
        }
        $this->showExitHint = false;

        if ($this->pendingHost !== '') {
            $this->raiseConnectingCover($this->pendingHost, $this->pendingPort);

            Discovery::connect($this->pendingHost, $this->pendingPort);
        }
    }

    /**
     * Idempotent close (the sheet fires @dismiss on its own slide-down too,
     * including after connectPending closes it — a toggle would reopen it).
     * Deliberately does NOT mark the hint as seen: backing out without
     * connecting means the user never saw the gesture in action.
     */
    public function dismissExitHint(): void
    {
        $this->showExitHint = false;
    }
}
