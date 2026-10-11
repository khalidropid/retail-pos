#!/usr/bin/env bash
#
# Builds dist/RetailPOS-Setup.exe (Windows 10/11 x64 installer) on Linux.
#
# Requirements on the build machine: git, php 8.4 + composer, node/npm (with the
# project's node_modules installed), curl, 7z, makensis (NSIS 3), sha256sum.
#
#   packaging/windows/build.sh            # uses committed code (git HEAD)
#
# What goes into the installer:
#   - Electron (Windows x64) running desktop/main.js
#   - Official PHP for Windows (NTS x64) + packaging/windows/php.ini
#   - Official PostgreSQL 17 binaries (no data, no pgAdmin)
#   - The Laravel app from git HEAD, production dependencies only, built assets
#   - Installer scripts (setup.ps1, repair.ps1, env.template) + VC++ runtime
# What never goes in: .env, development database, storage contents, tests.
#
set -euo pipefail

ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
HERE="$ROOT/packaging/windows"
CACHE="$HERE/.cache"
BUILD="$ROOT/build/windows"
STAGE="$BUILD/stage"
DIST="$ROOT/dist"

# shellcheck source=versions.env
source "$HERE/versions.env"
VERSION="${VERSION:-$(node -p "require('$ROOT/desktop/package.json').version")}"

say() { printf '\n==> %s\n' "$*"; }
need() { command -v "$1" >/dev/null || { echo "Missing tool: $1" >&2; exit 1; }; }
for t in git php composer npm curl 7z makensis sha256sum; do need "$t"; done

if [ -n "$(git -C "$ROOT" status --porcelain -- . ':!dist' ':!build')" ]; then
    echo "WARNING: uncommitted changes are NOT included (the installer is built from git HEAD)." >&2
fi

# ------------------------------------------------------------- downloads
mkdir -p "$CACHE"
fetch() { # url file sha256
    local url="$1" file="$CACHE/$2" sum="$3"
    if [ ! -s "$file" ]; then
        say "Downloading $2"
        curl -fsSL --retry 10 --retry-delay 5 --connect-timeout 30 -o "$file.part" "$url"
        mv "$file.part" "$file"
    fi
    echo "$sum  $file" | sha256sum -c --quiet - || { echo "Checksum mismatch: $2 (delete it from $CACHE and retry)" >&2; exit 1; }
}

ELECTRON_ZIP="electron-v${ELECTRON_VERSION}-win32-x64.zip"
PHP_ZIP="php-${PHP_VERSION}-nts-Win32-vs17-x64.zip"
PG_ZIP="postgresql-${PG_VERSION}-windows-x64-binaries.zip"

fetch "https://github.com/electron/electron/releases/download/v${ELECTRON_VERSION}/${ELECTRON_ZIP}" "$ELECTRON_ZIP" "$ELECTRON_SHA256"
fetch "https://downloads.php.net/~windows/releases/${PHP_ZIP}" "$PHP_ZIP" "$PHP_SHA256"
fetch "https://get.enterprisedb.com/postgresql/${PG_ZIP}" "$PG_ZIP" "$PG_SHA256"
fetch "https://aka.ms/vs/17/release/vc_redist.x64.exe" "vc_redist.x64.exe" "$VCREDIST_SHA256"

rm -rf "$BUILD"
mkdir -p "$STAGE/resources" "$DIST"

# ------------------------------------------------------------- Electron
say "Electron ${ELECTRON_VERSION}"
7z x -y -bso0 -bsp0 "$CACHE/$ELECTRON_ZIP" -o"$STAGE" >/dev/null
mv "$STAGE/electron.exe" "$STAGE/RetailPOS.exe"
rm -f "$STAGE/resources/default_app.asar"
mkdir -p "$STAGE/resources/app"
cp "$ROOT/desktop/main.js" "$ROOT/desktop/splash.html" "$STAGE/resources/app/"
node -e "const p=require('$ROOT/desktop/package.json'); delete p.devDependencies; delete p.scripts; p.version='$VERSION'; require('fs').writeFileSync('$STAGE/resources/app/package.json', JSON.stringify(p,null,2));"

# ------------------------------------------------------------------ PHP
say "PHP ${PHP_VERSION}"
7z x -y -bso0 -bsp0 "$CACHE/$PHP_ZIP" -o"$STAGE/resources/php" >/dev/null
cp "$HERE/php.ini" "$STAGE/resources/php/php.ini"
rm -f "$STAGE/resources/php/phpdbg.exe" "$STAGE/resources/php/php.ini-development"

# ----------------------------------------------------------- PostgreSQL
say "PostgreSQL ${PG_VERSION} (binaries only)"
7z x -y -bso0 -bsp0 "$CACHE/$PG_ZIP" -o"$BUILD/pg" >/dev/null
mkdir -p "$STAGE/resources/pgsql"
for d in bin lib share; do cp -r "$BUILD/pg/pgsql/$d" "$STAGE/resources/pgsql/"; done
cp "$BUILD/pg/pgsql/"*.txt "$STAGE/resources/pgsql/" 2>/dev/null || true # license
rm -rf "$BUILD/pg"
find "$STAGE/resources/pgsql" -name '*.pdb' -delete

# -------------------------------------------------------------- Laravel
say "Laravel app (git HEAD, production dependencies)"
SRC="$BUILD/laravel"
mkdir -p "$SRC"
git -C "$ROOT" archive HEAD | tar -x -C "$SRC"
(cd "$SRC" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)
(cd "$SRC" && php artisan filament:assets --no-ansi >/dev/null)

# Frontend: the project's own Vite build (run in the repo, where node_modules exists).
(cd "$ROOT" && npm run build --silent >/dev/null 2>&1) || { echo "npm run build failed" >&2; exit 1; }
rm -rf "$SRC/public/build" && cp -r "$ROOT/public/build" "$SRC/public/build"

# Strip development-only and machine-specific files.
rm -rf "$SRC/tests" "$SRC/desktop" "$SRC/packaging" "$SRC/node_modules" "$SRC/.github" \
       "$SRC/phpunit.xml" "$SRC/CLAUDE.md" "$SRC/.env" "$SRC/.env.example" "$SRC/public/hot" "$SRC/public/storage"
find "$SRC/storage" -type f ! -name '.gitignore' -delete
rm -f "$SRC/bootstrap/cache/"*.php
mv "$SRC" "$STAGE/resources/laravel"

# ------------------------------------------------------ installer files
mkdir -p "$STAGE/resources/installer"
cp "$HERE/setup.ps1" "$HERE/repair.ps1" "$HERE/env.template" "$CACHE/vc_redist.x64.exe" "$STAGE/resources/installer/"

# --------------------------------------------------------------- NSIS
say "Building installer"
ICON_ARG=()
[ -f "$HERE/icon.ico" ] && ICON_ARG=(-DAPP_ICON="$HERE/icon.ico")
makensis -V2 -INPUTCHARSET UTF8 "-DSTAGE_DIR=$STAGE" "-DAPP_VERSION=$VERSION" "-DOUT_FILE=$DIST/RetailPOS-Setup.exe" "${ICON_ARG[@]}" "$HERE/installer.nsi"

(cd "$DIST" && sha256sum RetailPOS-Setup.exe > RetailPOS-Setup.exe.sha256)
say "Done: $DIST/RetailPOS-Setup.exe ($(du -h "$DIST/RetailPOS-Setup.exe" | cut -f1))"
cat "$DIST/RetailPOS-Setup.exe.sha256"
