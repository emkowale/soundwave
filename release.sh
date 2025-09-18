#!/usr/bin/env bash
set -euo pipefail
BUMP="${1:-patch}"
# detect main plugin file (or override with PLUGIN_FILE=path)
PLUGIN_FILE="${PLUGIN_FILE:-$(grep -ril '^\s*\*\s*Plugin Name:' -- */*.php ./*.php | head -n1)}"
[[ -n "${PLUGIN_FILE:-}" && -f "$PLUGIN_FILE" ]] || { echo "❌ Plugin file not found"; exit 1; }
CUR=$(grep -iE '^\s*\*\s*Version:' "$PLUGIN_FILE" | head -n1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+') || { echo "❌ Version not found"; exit 1; }
IFS=. read -r MA MI PA <<<"$CUR"
case "$BUMP" in patch) PA=$((PA+1));; minor) MI=$((MI+1)); PA=0;; major) MA=$((MA+1)); MI=0; PA=0;; *) echo "Usage: $0 [patch|minor|major]"; exit 1;; esac
NEW="$MA.$MI.$PA"
# bump header Version
t=$(mktemp); sed -E "s/^(\s*\*\s*Version:\s*)[0-9]+\.[0-9]+\.[0-9]+/\1$NEW/" "$PLUGIN_FILE" >"$t" && mv "$t" "$PLUGIN_FILE"
# bump ANY *_VERSION constant if present in the same file (first match)
t=$(mktemp); sed -E "0,/(define\(\s*'([A-Z0-9_]+_VERSION)'\s*,\s*')[0-9]+\.[0-9]+\.[0-9]+('\s*\);)/{s//\1$NEW\3/}" "$PLUGIN_FILE" >"$t" && mv "$t" "$PLUGIN_FILE"
echo "Releasing v$NEW…"
git add -A
git commit -m "Release v$NEW" || true
git push origin HEAD
git tag -a "v$NEW" -m "v$NEW" || true
git push origin "v$NEW"
echo "✅ Tag v$NEW pushed. GitHub Actions will build & attach the zip."
