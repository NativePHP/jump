<p align="center">
  <a href="https://nativephp.com" target="_blank">
    <img src="https://nativephp.com/logo.svg" width="320" alt="NativePHP Logo">
  </a>
</p>

<h1 align="center">⚡ Jump</h1>

<p align="center">
  <strong>An instant Laravel runtime for your phone.</strong><br>
  Scan a QR code and run a real Laravel + NativePHP project on your device — no rebuild, no app store.
</p>

<p align="center">
  <a href="https://nativephp.com/docs/mobile"><img src="https://img.shields.io/badge/NativePHP-Mobile-4F46E5?style=flat-square" alt="NativePHP Mobile"></a>
  <img src="https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel" alt="Laravel 13">
  <img src="https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php" alt="PHP 8.4">
  <img src="https://img.shields.io/badge/iOS%20%7C%20Android-native-000000?style=flat-square" alt="iOS & Android">
</p>

---

## What is Jump?

**Jump** is the NativePHP mobile dev client — a native iOS & Android app that turns your phone into a live Laravel runtime. Start the Jump CLI in any Laravel project, scan the QR code, and your app streams to the device and runs there natively. Save a file and it hot-reloads. Reach for the camera, push notifications, biometrics, or geolocation and they execute right on the phone through NativePHP's Laravel APIs.

This repository *is* the Jump app itself — a Laravel application rendered entirely with NativePHP's [`native-ui`](https://nativephp.com/docs/mobile) engine, so the whole UI (native tab bars, nav bars, bottom sheets, SF Symbols / Material icons) is described in Blade and drawn with real SwiftUI and Jetpack Compose. No web view.

## How it works

```
┌────────────────┐        ┌────────────────┐        ┌────────────────┐
│  Your Laravel   │  mDNS  │   Jump app     │   WS   │  native-ui     │
│  project        │◄──────►│  (this repo)   │◄──────►│  render tree   │
│  php artisan    │  scan  │  on your phone │ stream │  SwiftUI /      │
│  native:jump    │        │                │        │  Compose        │
└────────────────┘        └────────────────┘        └────────────────┘
```

1. **Start the Jump CLI** — in a Laravel app, `composer require nativephp/mobile`, then `php artisan native:jump` to prepare it for the mobile runtime.
2. **Scan the QR code** — a QR appears in your terminal. Tap **Connect** in Jump and point your camera at it.
3. **Instant connection** — your phone connects over the local network (advertised via `_jump._tcp` mDNS) and runs the project in the Jump runtime.
4. **Explore native APIs** — camera, push notifications, biometrics, geolocation and more, straight from NativePHP's Laravel facades.
5. **Live reload** — save a file and the runtime reloads automatically. Shake to exit and return to Jump when you're done.

Servers advertising on your LAN show up automatically as a **"N servers nearby"** pill floating above the tab bar — tap to connect without scanning.

## Inside the app

Jump is built from NativePHP `native-ui` components (`app/NativeComponents`) rendered by native Blade views (`resources/views/native`):

| Tab | Component | What it does |
|-----|-----------|--------------|
| **Connect** | `Home` | Hero launcher — scan a QR, browse LAN dev servers, jump to the Playground, docs & partners |
| **Docs** | `Docs` | Browsable NativePHP mobile documentation with deep-linkable pages |
| **Videos** | `Videos` | Tutorials for building Laravel on mobile |
| **Search** | *(tab bar)* | Native docs search available from every tab |
| **Settings** | `Settings` | App preferences (notifications, etc.) |

Notable pieces:

- **`JumpTabsLayout`** — native bottom tab bar + top nav lockup (the `bolt.fill` + *Jump* wordmark), plus the app-wide discovery pill via `FloatingOverlay`.
- **LAN discovery** — `InteractsWithDiscovery` + `DiscoveredServers` browse `_jump._tcp` and surface nearby dev servers in a native bottom sheet.
- **"How Jump Works"** — the onboarding shown as a dismissable native `<bottom-sheet>` on the Connect screen.
- **Theme** — the *Deep Horizon* palette with an indigo (`#4F46E5`) brand tint, with full light/dark support via `theme-*` classes.

## Native capabilities

Jump ships with the NativePHP mobile API surface it demonstrates and uses:

`biometrics` · `browser` · `camera` · `clipboard` · `discovery` · `geolocation` · `local-notifications` · `media-player` · `microphone` · `network` · `scanner` · `secure-storage` · `share` · `vibe`

## Requirements

- **PHP** 8.4+
- **Laravel** 13.x
- [`nativephp/mobile`](https://nativephp.com/docs/mobile) + [`nativephp/native-ui`](https://nativephp.com)
- Xcode (iOS) and/or Android Studio for local device builds

## Getting started

```bash
# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Run the native app on a simulator / device
php artisan native:run
```

> A `./native` wrapper is included, so the common commands shorten to
> `./native run`, `./native jump`, and `./native install`.

> The generated native projects (`nativephp/ios`, `nativephp/android`) and build logs live under `nativephp/` and are git-ignored — they're produced by the NativePHP build, not checked in.

## Learn more

- 📚 [NativePHP Mobile Docs](https://nativephp.com/docs/mobile)
- 🌐 [nativephp.com](https://nativephp.com)
- 🧩 [Laravel](https://laravel.com)

## License

Open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
