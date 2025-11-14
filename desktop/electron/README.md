# Atlas POS Desktop Shell

The Electron shell wraps the Atlas POS web experience for kiosk-style deployments on Windows and macOS.

## Development

```bash
npm run dev:desktop
```

The command runs the web POS dev server, watches the Electron main/preload sources, and restarts Electron as needed. The shell opens in fullscreen kiosk mode and points to the Vite dev server (`http://localhost:5173`).

## Building

```bash
npm run build:desktop
```

Steps included in the build:

1. Build the web POS (`apps/web-pos`).
2. Compile Electron main/preload TypeScript sources.
3. Copy the web build into the Electron bundle.
4. Package distributables (`.exe` via NSIS and `.dmg`) with `electron-builder` into `desktop/electron/release`.

> **Note:** Code signing configuration and update server URLs are placeholders. Update the `build` section of `package.json` before shipping.

## Runtime Integrations

- **Secure token storage:** API tokens are stored via the OS keychain (Keytar) with filesystem fallback.
- **Offline storage:** Cart snapshots and queued orders are persisted under `app.getPath('userData')/offline`.
- **USB printing:** Rendered receipts are formatted as ESC/POS commands and sent to the first available USB thermal printer. Extend the renderer to expose printer selection if more control is required.
- **Auto updates:** Hooks for `electron-updater` are available via `window.atlasDesktop.updater`. Configure your update feed before distribution.

## Platform Notes

- Provide platform icons under `desktop/electron/build` (e.g. `icon.ico`, `icon.icns`) before packaging.
- macOS builds are hardened runtime enabled and require valid signing certificates for distribution.
