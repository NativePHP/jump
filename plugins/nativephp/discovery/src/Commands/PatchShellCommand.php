<?php

namespace NativePHP\Discovery\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * Pre-compile hook: durably re-apply the discovery plugin's dual-mode fork to
 * the generated shell's NativeElementBridge on every build.
 *
 * The plugin's Swift/Kotlin (runtime + relay) ship as normal plugin resources
 * and are copied in automatically. But making tap events route to the remote
 * WebSocket when a Jump session is live requires a ONE-LINE fork inside the
 * shell's own NativeElementBridge — a file `native:install` regenerates and
 * would otherwise clobber. Running this as a `pre_compile` hook re-inserts the
 * fork before each compile, idempotently, so it survives regeneration.
 *
 * On Android this hook also enables cleartext traffic for RELEASE builds
 * (see allowAndroidCleartext()) — without it, store builds cannot reach
 * plain-HTTP LAN dev servers at all.
 */
class PatchShellCommand extends NativePluginHookCommand
{
    protected $signature = 'native:discovery:patch-shell';

    protected $description = 'Re-apply the discovery dual-mode event fork to the generated shell';

    /** Sentinel so the patch is applied at most once per file. */
    private const MARKER = 'JumpElementRuntime';

    public function handle(): int
    {
        if ($this->isIos()) {
            return $this->patchIos();
        }

        if ($this->isAndroid()) {
            $this->allowAndroidCleartext();

            return $this->patchAndroid();
        }

        return self::SUCCESS;
    }

    /**
     * Permit cleartext (plain http/ws) traffic in RELEASE builds.
     *
     * Jump sessions ARE plain HTTP to LAN dev servers (the /jump/info
     * handshake, the ws:// bridge socket, the runloop-drive GET), and Android
     * blocks cleartext in release builds via the shell's
     * network_security_config.xml (`cleartextTrafficPermitted="false"`; only
     * debug builds get the permissive src/debug overlay). Android offers no
     * IP-range scoping for cleartext, so the base-config flag is the only
     * lever. The whole 9-line resource is rewritten each build — idempotent,
     * and immune to the anchor-drift problems of string-patching. The
     * AndroidManifest is untouched (it already points at this file).
     */
    private function allowAndroidCleartext(): void
    {
        $file = $this->buildPath().'/app/src/main/res/xml/network_security_config.xml';
        if (! is_file($file)) {
            $this->warn("discovery: network_security_config.xml not found at {$file} — cleartext NOT enabled; release builds cannot reach LAN dev servers.");

            return;
        }

        if (str_contains(file_get_contents($file), '[discovery] cleartext')) {
            $this->info('discovery: release cleartext already enabled.');

            return;
        }

        file_put_contents($file, <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <network-security-config>
            <!-- [discovery] cleartext enabled: Jump connects to plain-HTTP LAN dev
                 servers, which release builds block by default. Rewritten on every
                 build by native:discovery:patch-shell (pre_compile hook). -->
            <base-config cleartextTrafficPermitted="true">
                <trust-anchors>
                    <certificates src="system" />
                </trust-anchors>
            </base-config>
        </network-security-config>
        XML);

        $this->info('discovery: enabled release cleartext in network_security_config.xml.');
    }

    private function patchIos(): int
    {
        $file = $this->buildPath().'/NativePHP/NativeRender/NativeElementBridge.swift';
        if (! is_file($file)) {
            $this->warn("discovery: iOS NativeElementBridge not found at {$file} — skipping fork.");

            return self::SUCCESS;
        }

        $src = file_get_contents($file);
        if (str_contains($src, self::MARKER)) {
            $this->info('discovery: iOS event fork already present.');

            return self::SUCCESS;
        }

        $anchor = 'private static func writeEvent(type: Int, callbackId: Int, nodeId: Int, data: Data?) {';
        if (! str_contains($src, $anchor)) {
            $this->warn('discovery: could not find writeEvent() anchor in iOS shell — fork NOT applied.');

            return self::SUCCESS;
        }

        $injection = $anchor."\n"
            ."        // [discovery] dual-mode fork: route taps to the remote WebSocket\n"
            ."        // when a Jump session is live, instead of embedded shared memory.\n"
            ."        if JumpElementRuntime.shared.isActive {\n"
            ."            JumpElementRuntime.shared.enqueueRawEvent(type: type, callbackId: callbackId, nodeId: nodeId, data: data)\n"
            ."            return\n"
            ."        }\n";

        file_put_contents($file, str_replace($anchor, $injection, $src));
        $this->info('discovery: applied iOS event fork to NativeElementBridge.swift.');

        return self::SUCCESS;
    }

    private function patchAndroid(): int
    {
        $file = $this->buildPath().'/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeElementBridge.kt';
        if (! is_file($file)) {
            $this->warn("discovery: Android NativeElementBridge not found at {$file} — skipping fork.");

            return self::SUCCESS;
        }

        $src = file_get_contents($file);
        if (str_contains($src, self::MARKER)) {
            $this->info('discovery: Android event fork already present.');

            return self::SUCCESS;
        }

        // send*() helpers all call the JNI `nativeElementWriteEvent(EventType.…)`.
        // That external fun can't be edited, so route every call site through a
        // Kotlin `writeEvent` funnel that forks to the remote runtime when a
        // Jump session is live. (The funnel's own call uses the lowercase
        // `type` arg, so it's never rewritten by the replace below.)
        $anchor = 'fun sendPressEvent(callbackId: Int, nodeId: Int';
        if (! str_contains($src, $anchor) || ! str_contains($src, 'nativeElementWriteEvent(EventType.')) {
            $this->warn('discovery: could not find send*/nativeElementWriteEvent anchors — Android fork NOT applied.');

            return self::SUCCESS;
        }

        // 1. import the runtime (after the package line)
        $src = preg_replace(
            '/^(package com\.nativephp\.mobile\.ui\.nativerender.*$)/m',
            "$1\n\nimport com.nativephp.discovery.JumpElementRuntime",
            $src,
            1
        );

        // 2. inject the funnel just before the first send* helper
        $funnel = <<<'KT'
        // [discovery] dual-mode fork: route taps to the remote WebSocket when a
        // Jump session is live, instead of the embedded JNI event ring.
        private fun writeEvent(type: Int, callbackId: Int, nodeId: Int, data: ByteArray?) {
            if (JumpElementRuntime.isActive) {
                JumpElementRuntime.enqueueRawEvent(type, callbackId, nodeId, data)
                return
            }
            nativeElementWriteEvent(type, callbackId, nodeId, data)
        }

        KT;
        $src = preg_replace('/(\s*)('.preg_quote($anchor, '/').')/', "\n\n{$funnel}$1$2", $src, 1);

        // 3. route every send* call site through the funnel
        $src = str_replace('nativeElementWriteEvent(EventType.', 'writeEvent(EventType.', $src);

        file_put_contents($file, $src);
        $this->info('discovery: applied Android event fork to NativeElementBridge.kt.');

        return self::SUCCESS;
    }
}
