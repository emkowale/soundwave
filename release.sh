#!/usr/bin/env bash
# Usage: release.sh [patch|minor|major]
set -euo pipefail

usage(){ echo "Usage: $(basename "$0") [patch|minor|major]"; exit 2; }
[[ $# -eq 1 ]] || usage
BUMP="$1"; [[ "$BUMP" =~ ^(patch|minor|major)$ ]] || usage

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

FILE="soundwave.php"
[[ -f "$FILE" ]] || { echo "❌ $FILE not found"; exit 1; }

# Require clean working tree
if [[ -n "$(git status --porcelain)" ]]; then
  echo "❌ Working tree not clean. Commit/stash first."; exit 1
fi

# Get current version from header; fallback to constant
CURV="$(grep -Eo '^\s*\*\s*Version:\s*[0-9]+\.[0-9]+\.[0-9]+' "$FILE" | awk '{print $3}' || true)"
[[ -n "$CURV" ]] || CURV="$(grep -Eo "SOUNDWAVE_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'" "$FILE" | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/")"
[[ -n "$CURV" ]] || { echo "❌ Could not detect current version"; exit 1; }

IFS='.' read -r MAJ MIN PAT <<<"$CURV"
case "$BUMP" in
  patch) PAT=$((PAT+1));;
  minor) MIN=$((MIN+1)); PAT=0;;
  major) MAJ=$((MAJ+1)); MIN=0; PAT=0;;
esac
NEWV="${MAJ}.${MIN}.${PAT}"

echo "ℹ️  Bumping $CURV -> $NEWV"

# Update header Version and constant
# (Keep spacing; only replace the number)
sed -i -E "s/^(\s*\*\s*Version:\s*)[0-9]+\.[0-9]+\.[0-9]+/\1${NEWV}/" "$FILE"
sed -i -E "s/(SOUNDWAVE_VERSION'\s*,\s*')[0-9]+\.[0-9]+\.[0-9]+(')/\1${NEWV}\2/" "$FILE"

# Ensure .gitattributes exists (export-clean)
if [[ ! -f .gitattributes ]]; then
  cat > .gitattributes <<'EOF'
/.*          export-ignore
/.github/    export-ignore
/backup_*/   export-ignore
/release.sh  export-ignore
/tests/      export-ignore
/*.md        export-ignore
EOF
fi

git add "$FILE" .gitattributes
git commit -m "release: v${NEWV}"
git tag -a "v${NEWV}" -m "v${NEWV}"

# Build WordPress-safe zip: EVERYTHING under soundwave/
ZIP="soundwave-v${NEWV}.zip"
git archive --format=zip --prefix=soundwave/ -o "$ZIP" HEAD
echo "📦 Built $ZIP"

# Validate: no root-level entries allowed (must all start with soundwave/)
if unzip -Z1 "$ZIP" | grep -E '^[^/]+$' >/dev/null; then
  echo "❌ Invalid zip: found root-level entries. All paths must start with soundwave/"; exit 1
fi
echo "✅ Zip rooted at soundwave/"

# Push main + tags
git push origin HEAD:main --tags
echo "✅ Pushed main and tag v${NEWV}"

# If GitHub CLI is available, create/update the release and upload asset
if command -v gh >/dev/null 2>&1; then
  gh release view "v${NEWV}" >/dev/null 2>&1 || gh release create "v${NEWV}" --title "v${NEWV}" --notes "Release ${NEWV}"
  gh release upload "v${NEWV}" "$ZIP" --clobber
  echo "✅ Uploaded $ZIP to GitHub release v${NEWV}"
else
  echo "ℹ️  Install GitHub CLI (gh) to auto-upload asset, or upload $ZIP to the release manually."
fi

echo "🎉 Done. Version v${NEWV} is ready."
