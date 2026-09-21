# MixpostMCP Desktop

A small Laravel app that wraps the MixpostMCP package with [NativePHP for Desktop](https://nativephp.com)
and ships it as a normal Windows or macOS program. It bundles its own PHP, runs on a single SQLite file
in your app-data folder, and needs no server, Docker, MySQL or Redis. The queue worker and the scheduler
run inside the app, so scheduled posts go out while it is open.

What it does **not** do:

- **Instagram publishing.** Instagram fetches media from a public URL, and the desktop app serves media
  only to itself. Use the server install for Instagram.
- **Publish while closed.** Posts are sent by the app's own worker; quit the app and nothing goes out
  until you open it again.
- **Connect networks yet.** Adding a social account needs an OAuth callback the desktop app cannot
  receive yet. That arrives in the next version.

## Build on Windows

You need:

- **PHP 8.3** (NTS, x64) with **Composer** — e.g. the `php-8.3` folder installed under
  `%LOCALAPPDATA%\Programs`, both on your `PATH`
- **Node 22 or newer** (nvm-windows is fine)
- Git Bash to run `build.sh`

Then, from the repository root:

```bash
npm install && npm run build
cd desktop
composer install
npm install
./build.sh win
```

`build.sh` rebuilds the package assets, refreshes the copy of the package inside `vendor/`, copies
ffmpeg into `extras/`, and runs `php artisan native:build`. The installer lands in
`desktop/nativephp/electron/dist/MixpostMCP-<version>-setup.exe`. It installs per user (no admin
prompt) into `%LOCALAPPDATA%\Programs\mixpostmcp` and runs silently with `/S`.

If your terminal is itself an Electron app (VS Code, Claude Code) it sets `ELECTRON_RUN_AS_NODE=1`,
which makes the build — and the installed app — start Electron as plain Node and exit. `build.sh`
unsets it; unset it yourself before launching `mixpostmcp.exe` from such a terminal.

## Build on macOS

You need:

- **PHP 8.3 and Composer** — [Laravel Herd](https://herd.laravel.com) gives you both, or
  `brew install php@8.3 composer`
- **Node 22 or newer**
- **Xcode Command Line Tools** (`xcode-select --install`)

Same commands, different target:

```bash
npm install && npm run build
cd desktop
composer install
npm install
./build.sh mac
```

The `.dmg` lands in `desktop/nativephp/electron/dist/`. `build.sh` builds for the Mac it runs on:
arm64 on Apple Silicon, x64 on Intel. The build is not signed or notarised, so Gatekeeper blocks it the
first time: right-click the app → **Open**, or run `xattr -d com.apple.quarantine /Applications/MixpostMCP.app`.

## AI agents (Claude Desktop)

The app ships the MixpostMCP MCP server. It must run against the *installed* app's PHP, code and
database, and those paths differ per machine, so the app writes a launcher script into its app-data
folder every time it starts:

- Windows: `%APPDATA%\mixpostmcp\mcp\mixpostmcp-mcp.cmd`
- macOS: `~/Library/Application Support/mixpostmcp/mcp/mixpostmcp-mcp.sh`

You do not have to find it. In the app, open **Help → Copy Claude Desktop config**; the ready-to-paste
block is on your clipboard:

```json
{
    "mcpServers": {
        "mixpostmcp": {
            "command": "C:\\Users\\you\\AppData\\Roaming\\mixpostmcp\\mcp\\mixpostmcp-mcp.cmd"
        }
    }
}
```

Paste it into `claude_desktop_config.json` (Claude Desktop → Settings → Developer → Edit Config) and
restart Claude Desktop. The app has to be installed and to have been opened at least once, because the
launcher is written at start-up; it does not have to be open while an agent uses it. The launcher holds
only paths — no secrets. See the root README for what the tools can do.

## Where your data lives

Everything the app creates is in one folder — **Help → Open data folder** takes you there:

- Windows: `%APPDATA%\mixpostmcp`
- macOS: `~/Library/Application Support/mixpostmcp`

Inside: `database/database.sqlite` (accounts, posts, settings), `storage/app/mixpostmcp-media/` (uploads),
`storage/app/app.key` (the encryption key for saved network tokens — generated on first run, unique to
this install), `storage/logs/`, and `mcp/` (the launcher above).

**To reset the app**, quit it and delete that folder. Everything, including the key, is recreated on the
next start. Uninstalling leaves the folder in place.

## Icon

`public/icon.png` (1024×1024) is the only icon file; NativePHP derives the `.ico` and `.icns` from it at
build time. Replace that one file to change the icon everywhere. The current one is a placeholder.

## Licences

MixpostMCP is MIT. The bundled `ffmpeg` and `ffprobe` (from the `ffmpeg-static` and `ffprobe-static`
npm packages) are GPL-licensed. They ship as separate executables in `extras/ffmpeg/` that the app runs
as external programs, with their licence text alongside; they are not linked into MixpostMCP.
