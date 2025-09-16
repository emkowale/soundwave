#!/usr/bin/env bash
set -euo pipefail
BUMP="${1:-patch}"
PLUGIN_FILE="soundwave.php"

# read & bump version
CUR=$(grep -iE '^\s*\*\s*Version:' "$PLUGIN_FILE" | head -n1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+') || { echo "Version not found"; exit 1; }
IFS=. read -r MA MI PA <<<"$CUR"
case "$BUMP" in patch) PA=$((PA+1));; minor) MI=$((MI+1)); PA=0;; major) MA=$((MA+1)); MI=0; PA=0;; *) echo "Usage: $0 [patch|minor|major]"; exit 1;; esac
NEW="$MA.$MI.$PA"

# bump header + constant
t=$(mktemp); sed -E "s/^(\s*\*\s*Version:\s*)[0-9]+\.[0-9]+\.[0-9]+/\1$NEW/" "$PLUGIN_FILE" >"$t" && mv "$t" "$PLUGIN_FILE"
t=$(mktemp); sed -E "s/(define\('SOUNDWAVE_VERSION',\s*')[0-9]+\.[0-9]+\.[0-9]+('\);)/\1$NEW\2/" "$PLUGIN_FILE" >"$t" && mv "$t" "$PLUGIN_FILE"

echo "Releasing Soundwave v$NEW… (SSH push, Actions will build & attach ZIP)"
git add -A
git commit -m "Release v$NEW" || true
git push origin main
git tag -a "v$NEW" -m "Soundwave v$NEW" || true
git push origin "v$NEW"
echo "Tag v$NEW pushed. GitHub Actions will publish the release + zip."
