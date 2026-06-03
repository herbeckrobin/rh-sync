#!/usr/bin/env bash
#
# Baut ein WordPress.org-taugliches ZIP des Plugins.
#
# Unterschied zum GitHub-Release (release.yml):
#   - Auto-Update-Checker wird entfernt (inc/UpdateChecker.php +
#     yahnis-elsts/plugin-update-checker). Auf WordPress.org verboten,
#     dort liefert WordPress die Updates selbst.
#   - Der "Update URI"-Header wird entfernt (zeigt sonst auf GitHub).
#
# Ergebnis: dist/<slug>-wporg.zip
#
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="$(basename "$SRC")"
DIST="${SRC}/dist"
BUILD="$(mktemp -d)/wporg"
DEST="${BUILD}/${SLUG}"

echo "==> WordPress.org-Build fuer ${SLUG}"
mkdir -p "$DEST" "$DIST"

# 1) Quelle ins Build-Verzeichnis spiegeln (Dev-Dateien per .distignore raus).
rsync -a --exclude-from="${SRC}/.distignore" "${SRC}/" "${DEST}/"

cd "$DEST"

# 2) Auto-Update-Checker entfernen.
rm -f inc/UpdateChecker.php

# GitHub-Auth fuer Composer (gegen Rate-Limit beim VCS-Ziehen von core/db-engine).
if command -v gh >/dev/null 2>&1; then
  composer config --global github-oauth.github.com "$(gh auth token)" >/dev/null 2>&1 || true
fi

composer remove yahnis-elsts/plugin-update-checker \
  --update-no-dev --no-interaction --no-progress --optimize-autoloader

# 3) "Update URI"-Header aus dem Plugin-Hauptfile loeschen.
sed -i.bak '/^ \* Update URI:/d' "${SLUG}.php" && rm -f "${SLUG}.php.bak"

# 4) ZIP schnueren.
ZIP="${DIST}/${SLUG}-wporg.zip"
rm -f "$ZIP"
( cd "$BUILD" && zip -rq "$ZIP" "$SLUG" -x '*.DS_Store' )

echo "==> Fertig: ${ZIP}"
