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

echo "Releasing Soundwave v$NEW …"

# commit, tag, push over SSH
git add -A
git commit -m "Release v$NEW" || true
git push origin main
git tag -a "v$NEW" -m "Soundwave v$NEW" || true
git push origin "v$NEW"

# build clean zip with folder 'soundwave/'
rm -rf dist .rel && mkdir -p dist .rel/soundwave
rsync -a --delete \
  --exclude ".git" --exclude "dist" --exclude ".rel" --exclude "*.zip" \
  --exclude ".gitignore" --exclude "release.sh" ./ .rel/soundwave/
( cd .rel && zip -r "../dist/soundwave-v$NEW.zip" soundwave >/dev/null )
ZIP="dist/soundwave-v$NEW.zip"

# create GitHub release + upload asset (needs GH_TOKEN=repo PAT)
: "${GH_TOKEN:?Set GH_TOKEN env var (GitHub token with repo scope)}"
SLUG=$(git config --get remote.origin.url | sed -E 's#(git@github.com:|https?://github.com/)##; s/\.git$//')
API="https://api.github.com/repos/$SLUG"

# create (or fetch) release
resp=$(curl -sS -H "Authorization: token $GH_TOKEN" -H "Accept: application/vnd.github+json" \
  -d "{\"tag_name\":\"v$NEW\",\"name\":\"Soundwave v$NEW\",\"body\":\"Auto release v$NEW\",\"draft\":false,\"prerelease\":false}" \
  "$API/releases") || true
REL_ID=$(echo "$resp" | grep -oE '"id":[0-9]+' | head -n1 | cut -d: -f2)
if [[ -z "${REL_ID:-}" ]]; then
  resp=$(curl -sS -H "Authorization: token $GH_TOKEN" "$API/releases/tags/v$NEW")
  REL_ID=$(echo "$resp" | grep -oE '"id":[0-9]+' | head -n1 | cut -d: -f2)
fi

# upload asset
curl -sS -X POST -H "Authorization: token $GH_TOKEN" -H "Content-Type: application/zip" \
  --data-binary @"$ZIP" \
  "https://uploads.github.com/repos/$SLUG/releases/$REL_ID/assets?name=$(basename "$ZIP")" >/dev/null

echo "✅ v$NEW published and asset uploaded: $ZIP"
