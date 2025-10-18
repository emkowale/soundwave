#!/usr/bin/env bash
set -euo pipefail

# === Soundwave release script (LOCAL IS SOURCE OF TRUTH) ======================
# Usage: ./release.sh {major|minor|patch}
# - Reads + bumps semver from the plugin header (Version: X.Y.Z) ONLY
# - Syncs define('SOUNDWAVE_VERSION', 'X.Y.Z') iff present exactly once
# - Prepends CHANGELOG from commits since last tag
# - Builds artifacts/soundwave-vX.Y.Z.zip with top-level "soundwave/"
# - Force-with-lease pushes main and replaces remote tag
# - Creates GitHub Release (gh if authed, else REST API with $GITHUB_TOKEN)

REPO_OWNER="emkowale"
REPO_NAME="soundwave"
REPO_SLUG="${REPO_OWNER}/${REPO_NAME}"
REMOTE_SSH="git@github.com:${REPO_SLUG}.git"

PLUGIN_DIR="soundwave"
MAIN_FILE="soundwave.php"
ARTIFACT_DIR="artifacts"
DEFAULT_BRANCH="main"

RSYNC_EXCLUDES=(
  ".git" ".github" ".gitignore" ".gitattributes" ".DS_Store"
  "*.zip" "node_modules" "vendor/*/.git" "tests" "Test" "tmp" "dist"
  ".idea" ".vscode" "package" "release.sh"
)

# ---------- UI ----------
color(){ printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
ok(){    color "32" "✔ $1"; }
info(){  color "36" "ℹ $1"; }
warn(){  color "33" "⚠ $1"; }
err(){   color "31" "✖ $1"; exit 1; }
require_cmd(){ command -v "$1" >/dev/null 2>&1 || err "Missing required command: $1"; }
today(){ date +"%Y-%m-%d"; }

# ---------- Args ----------
BUMP="${1:-}"
[[ -z "${BUMP}" || ! "${BUMP}" =~ ^(major|minor|patch)$ ]] && err "Usage: ./release.sh {major|minor|patch}"

# ---------- Tools ----------
require_cmd git
require_cmd rsync
require_cmd zip
# sed is assumed (BSD/macOS or GNU). No awk/perl needed.

# ---------- Sanity ----------
[ -f "$MAIN_FILE" ] || err "Run from the plugin root (where $MAIN_FILE exists)."
[ "$(basename "$PWD")" = "$PLUGIN_DIR" ] || warn "Dir is '$(basename "$PWD")'; expected '$PLUGIN_DIR' for clean zip structure."

# ---------- Git (local wins) ----------
if ! git rev-parse --git-dir >/dev/null 2>&1; then git init; fi
git config init.defaultBranch "$DEFAULT_BRANCH" >/dev/null 2>&1 || true
if ! git rev-parse --abbrev-ref HEAD >/dev/null 2>&1; then git checkout -b "$DEFAULT_BRANCH"; fi
if ! git remote get-url origin >/dev/null 2>&1; then
  git remote add origin "$REMOTE_SSH"
else
  cur="$(git remote get-url origin)"; [ "$cur" = "$REMOTE_SSH" ] || git remote set-url origin "$REMOTE_SSH"
fi

# ---------- Helpers (SED-only, BSD/GNU portable) ----------
get_header_version() {
  # print header block then extract first Version: x.y.z
  sed -n '1,/^\*\/$/p' "$MAIN_FILE" \
  | sed -n -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/p' \
  | head -n1
}

set_header_version() {
  NEW="$1"
  tmp="$(mktemp)"
  # Only modify lines from start through the first "*/"
  # Replace "Version: x.y.z" preserving indentation and optional leading "*"
  sed -E "1,/^\*\/$/ {
    s/^([[:space:]]*\*?[[:space:]]*Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\\1${NEW}/
  }" "$MAIN_FILE" > "$tmp"
  mv "$tmp" "$MAIN_FILE"
}

sync_define_if_unique() {
  NEW="$1"
  cnt="$(grep -cE "^[[:space:]]*define\\(\\s*'SOUNDWAVE_VERSION'\\s*,\\s*'[^']+'\\s*\\)\\s*;" "$MAIN_FILE" || true)"
  if [ "$cnt" = "1" ]; then
    tmp="$(mktemp)"
    sed -E "s/^([[:space:]]*define\(\s*'SOUNDWAVE_VERSION'\s*,\s*')[0-9]+\.[0-9]+\.[0-9]+('\s*\)\s*;)/\1${NEW}\2/" "$MAIN_FILE" > "$tmp"
    mv "$tmp" "$MAIN_FILE"
  elif [ "$cnt" = "0" ]; then
    warn "No SOUNDWAVE_VERSION define found; skipping define sync."
  else
    warn "Multiple SOUNDWAVE_VERSION defines found; skipping define sync."
  fi
}

# ---------- Read & bump version ----------
CUR_VER="$(get_header_version || true)"
[ -n "${CUR_VER:-}" ] || err "Could not find Version: in plugin header."

IFS='.' read -r MA MI PA <<< "$CUR_VER"
case "$BUMP" in
  major) MA=$((MA+1)); MI=0; PA=0 ;;
  minor) MI=$((MI+1)); PA=0 ;;
  patch) PA=$((PA+1)) ;;
esac
NEW_VER="${MA}.${MI}.${PA}"
TAG="v${NEW_VER}"
ok "Version bump: $CUR_VER → $NEW_VER"

# ---------- CHANGELOG ----------
LAST_TAG="$(git describe --tags --abbrev=0 2>/dev/null || true)"
RANGE="${LAST_TAG:+${LAST_TAG}..HEAD}"; : "${RANGE:=--root}"
NOTES="$(git log $RANGE --no-merges --pretty=format:'- %s (%h)')"
[ -n "$NOTES" ] || NOTES="- Maintenance release."
SECTION=$(
  cat <<EOF
## ${TAG} — $(today)

${NOTES}

EOF
)
if [ -f CHANGELOG.md ]; then
  tmpcl="$(mktemp)"; { printf "%s" "$SECTION"; cat CHANGELOG.md; } > "$tmpcl"; mv "$tmpcl" CHANGELOG.md
else
  printf "# Changelog\n\n%s" "$SECTION" > CHANGELOG.md
fi
ok "CHANGELOG.md updated."

# ---------- Apply version (SAFE) ----------
set_header_version "$NEW_VER"
sync_define_if_unique "$NEW_VER"

# Guard: no orphaned fragments like ".1.2.3');"
if grep -Eq "^[[:space:]]*\\.[0-9]+\\.[0-9]+\\.[0-9]+'\\);[[:space:]]*$" "$MAIN_FILE"; then
  err "Malformed version fragment detected in $MAIN_FILE. Aborting."
fi

# ---------- Optional: bump Stable tag in readme.txt ----------
if [ -f "readme.txt" ]; then
  if grep -Eq '^[[:space:]]*Stable tag:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' readme.txt; then
    tmpread="$(mktemp)"
    sed -E "s/^([[:space:]]*Stable tag:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\\1${NEW_VER}/" readme.txt > "$tmpread"
    mv "$tmpread" readme.txt
  fi
fi

# ---------- Commit & tag ----------
git add -A
git commit -m "Release ${TAG}" || info "Nothing to commit"
if git rev-parse "$TAG" >/dev/null 2>&1; then git tag -d "$TAG" >/dev/null 2>&1 || true; fi
git tag -a "$TAG" -m "Soundwave $TAG"

# ---------- Build zip (top-level "soundwave/") ----------
rm -rf "$ARTIFACT_DIR" package
mkdir -p "package/${PLUGIN_DIR}" "$ARTIFACT_DIR"
RSYNC_ARGS=(); for e in "${RSYNC_EXCLUDES[@]}"; do RSYNC_ARGS+=(--exclude "$e"); done
rsync -a --delete "${RSYNC_EXCLUDES[@]/#/--exclude }" ./ "package/${PLUGIN_DIR}/" 2>/dev/null || rsync -a --delete "${RSYNC_ARGS[@]}" ./ "package/${PLUGIN_DIR}/"
( cd package && zip -r "../$ARTIFACT_DIR/${REPO_NAME}-${TAG}.zip" "${PLUGIN_DIR}" >/dev/null )
ZIP_PATH="$ARTIFACT_DIR/${REPO_NAME}-${TAG}.zip"
[ -f "$ZIP_PATH" ] || err "Failed to build zip"
ok "Built $ZIP_PATH"

# ---------- Push (local is canonical) ----------
git branch -M "$DEFAULT_BRANCH" || true
git push -u origin "$DEFAULT_BRANCH" --force-with-lease || warn "Could not push main (check remote)"
git push origin ":refs/tags/${TAG}" >/dev/null 2>&1 || true
git push origin "$TAG" --force || warn "Could not push tag (check remote)"

# ---------- Release (gh if authed; else REST API with $GITHUB_TOKEN) ----------
can_use_gh=false
if command -v gh >/dev/null 2>&1; then
  if gh auth status -h github.com >/dev/null 2>&1; then can_use_gh=true; fi
fi

create_release_with_gh(){
  info "Creating GitHub release via gh"
  gh release delete "$TAG" -R "$REPO_SLUG" --yes >/dev/null 2>&1 || true
  gh release create "$TAG" "$ZIP_PATH" -R "$REPO_SLUG" -t "soundwave $TAG" -n "$SECTION" --verify-tag
}

create_release_with_api(){
  [ -n "${GITHUB_TOKEN:-}" ] || err "GITHUB_TOKEN not set; cannot create release via API"
  command -v jq >/dev/null 2>&1 || err "jq is required for API JSON handling"
  info "Creating GitHub release via REST API"
  API="https://api.github.com/repos/${REPO_SLUG}/releases"
  REL_ID="$(curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" "${API}/tags/${TAG}" | jq -r '.id // empty' || true)"
  if [ -n "$REL_ID" ]; then
    curl -fsSL -X DELETE -H "Authorization: token ${GITHUB_TOKEN}" "${API}/${REL_ID}" >/dev/null || true
  fi
  BODY_JSON=$(jq -n --arg tag "$TAG" --arg name "soundwave $TAG" --arg body "$SECTION" \
    '{tag_name:$tag, name:$name, body:$body, draft:false, prerelease:false}')
  RESP="$(curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" -H "Accept: application/vnd.github+json" -d "$BODY_JSON" "$API")"
  UPLOAD_URL="$(echo "$RESP" | jq -r '.upload_url' | sed -E 's/\{.*\}//')"
  [ -n "$UPLOAD_URL" ] || err "Failed to create release via API: $RESP"
  curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" -H "Content-Type: application/zip" \
    --data-binary @"$ZIP_PATH" \
    "${UPLOAD_URL}?name=$(basename "$ZIP_PATH")" >/dev/null || warn "Asset upload failed"
}

if "$can_use_gh"; then
  create_release_with_gh || err "gh release failed"
  ok "GitHub release created (gh)."
else
  warn "gh not authenticated; using API fallback."
  create_release_with_api
  ok "GitHub release created (API)."
fi

ok "Release complete: $TAG"
