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

## Connecting social networks

Every network needs an app of your own in its developer portal, exactly as on a server — the main
[README](../README.md#connecting-social-networks) walks through each one. The difference is the
callback URL you register. The desktop app runs on an address the networks cannot reach, so it uses
a fixed relay page hosted with this repository (trailing slash included):

```
https://zenfrogstudio.github.io/MixpostMCP/callback/<provider>/
```

| Network | Callback URL to register |
| --- | --- |
| Facebook Page | `https://zenfrogstudio.github.io/MixpostMCP/callback/facebook_page/` |
| Instagram | `https://zenfrogstudio.github.io/MixpostMCP/callback/instagram/` |
| X (Twitter) | `https://zenfrogstudio.github.io/MixpostMCP/callback/twitter/` |
| LinkedIn | `https://zenfrogstudio.github.io/MixpostMCP/callback/linkedin/` |
| TikTok | `https://zenfrogstudio.github.io/MixpostMCP/callback/tiktok/` |
| YouTube | `https://zenfrogstudio.github.io/MixpostMCP/callback/youtube/` |

Clicking **Connect** opens the network's sign-in page in your normal web browser. When you approve,
the network sends the browser to the relay page, which passes the result to the app through its
`mixpostmcp://` link and tells you the tab can be closed. **The app must be open when you come back.**
The relay page holds no credentials and stores nothing; it only forwards the network's reply.

The per-network caveats in the main README still apply here: Meta apps in Development mode can only
post to your own Pages, TikTok posts are private until the app passes TikTok's audit, and a YouTube
consent screen in Testing expires its tokens after seven days.

The relay pages live in `relay/` at the repository root and are published by the
`.github/workflows/relay-pages.yml` workflow. The app points at them through
`MIXPOSTMCP_OAUTH_CALLBACK_BASE` in `.env`; if you fork this project, host your own copy of `relay/`
and change that value.

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

`build.sh` rebuilds the package assets, refreshes the copy of the package inside `vendor/`, writes
`.env` from `.env.example` if there is none, copies ffmpeg into `extras/`, and finishes with
`php artisan native:build win --no-interaction`. It takes about ten minutes. The installer lands in
`desktop/nativephp/electron/dist/MixpostMCP-<version>-setup.exe` (the version is
`NATIVEPHP_APP_VERSION` in `.env`). It installs per user (no admin prompt) into
`%LOCALAPPDATA%\Programs\mixpostmcp` and runs silently with `/S`.

If your terminal is itself an Electron app (VS Code, Claude Code) it sets `ELECTRON_RUN_AS_NODE=1`,
which makes the build — and the installed app — start Electron as plain Node and exit. `build.sh`
unsets it; unset it yourself before launching `mixpostmcp.exe` from such a terminal.

### Releasing a new version

The version lives in three places; bump all of them together:

- `package.json` at the repository root (`version`)
- `desktop/.env.example` and your `desktop/.env` (`NATIVEPHP_APP_VERSION` — the installer's version)
- `desktop/composer.json` (`repositories[0].options.versions` — what the app shows as its own version
  on the Status page; without it Composer reports the git branch name instead)

## Build on macOS

You need:

- **PHP 8.3 and Composer** — [Laravel Herd](https://herd.laravel.com) gives you both, or
  `brew install php@8.3 composer`
- **Node 22 or newer** — `brew install node`
- **Xcode Command Line Tools** — `xcode-select --install`

Then, in order, from a fresh clone of the release you are building:

```bash
git clone https://github.com/ZenFrogStudio/MixpostMCP.git
cd MixpostMCP
git checkout v2.23.0          # the tag of the release you are building
npm install && npm run build  # the package's front-end assets
cd desktop
composer install              # PHP dependencies, including NativePHP
npm install                   # downloads the macOS ffmpeg/ffprobe binaries
./build.sh mac
```

`build.sh` does the same as on Windows and finishes with
`php artisan native:build mac arm64 --no-interaction` (`x64` on an Intel Mac — it picks the
architecture of the Mac it runs on). The first run also downloads the macOS PHP binary. The `.dmg`
lands in `desktop/nativephp/electron/dist/` and `build.sh` prints its path on the last line.

The build is not signed or notarised, so Gatekeeper blocks it the first time it is opened. On macOS 15
and later, open **System Settings → Privacy & Security**, scroll to the block message and click
**Open Anyway**; on older versions, right-click the app → **Open**. Either way, once is enough. The
command-line equivalent is `xattr -dr com.apple.quarantine /Applications/MixpostMCP.app`.

To add the build to a GitHub release, run
`gh release upload v2.23.0 nativephp/electron/dist/MixpostMCP-*.dmg` from `desktop/`.

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
