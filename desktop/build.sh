#!/usr/bin/env bash
# Build the MixpostMCP desktop installer.  Usage: ./build.sh <win|mac>
set -euo pipefail

platform="${1:-}"
if [[ "$platform" != "win" && "$platform" != "mac" ]]; then
    echo "Usage: $0 <win|mac>" >&2
    exit 1
fi

cd "$(dirname "$0")"

# Set by Electron-based terminals (VS Code, Claude Code); makes Electron run as plain Node and the build fail.
unset ELECTRON_RUN_AS_NODE

# 1. Build the package's front-end assets.
(cd .. && npm run build)

# 2. Refresh the mirrored copy of the package, then strip what Composer copied along with it.
#    Composer refuses to update a path-repo mirror that sits inside its own source, but it happily
#    installs one, so the old copy goes first.
rm -rf vendor/onemedialabs/mixpostmcp
composer update onemedialabs/mixpostmcp --no-interaction
rm -rf vendor/onemedialabs/mixpostmcp/{vendor,node_modules,desktop}

# 3. Environment file and app key.
[[ -f .env ]] || cp .env.example .env
if ! grep -qE '^APP_KEY=.+' .env; then
    php artisan key:generate --force
fi

# 4. Package assets (copied, not published: the mirrored copy may lack the git-ignored resources/dist).
rm -rf public/vendor/mixpostmcp
mkdir -p public/vendor
cp -R ../resources/dist/vendor/mixpostmcp public/vendor/mixpostmcp
cp ../resources/img/favicon.ico public/vendor/mixpostmcp/favicon.ico

# 5. ffmpeg binaries: extras/ffmpeg ships next to the app as <install>/extras/ffmpeg.
node scripts/copy-ffmpeg.mjs

# 6. Build the installer. Windows is x64 only; macOS builds for the Mac it runs on (arm64 on Apple Silicon).
arch=""
if [[ "$platform" == "mac" ]]; then
    arch=$([[ "$(uname -m)" == "arm64" ]] && echo arm64 || echo x64)
fi
php artisan native:build "$platform" $arch --no-interaction

# 7. Newest artifact (NativePHP 2.3 writes installers to nativephp/electron/dist).
echo
echo "Built: $(ls -t nativephp/electron/dist/*-setup.exe nativephp/electron/dist/*.dmg 2>/dev/null | head -1)"
