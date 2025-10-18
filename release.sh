#!/usr/bin/env bash
set -euo pipefail

# === Soundwave release script (LOCAL IS SOURCE OF TRUTH) ======================
# Usage: ./release.sh {major|minor|patch}
#
# - Bumps semver based on Version: in soundwave.php
# - Prepends CHANGELOG.md with commits since last tag
# - Updates Version: header and (if present exactly once) SOUNDWAVE_VERSION
# - Validates against malformed version fragments (e.g., ".1.2.3');")
# - Builds artifacts/soundwave-vX.Y.Z.zip with top-level folder "soundwave/"
# - Pushes main with --force-with-lease, replaces remote tag if exists
# - Creates GitHub Release, attaches the zip, uses the changelog section as notes
#
# Requires: git, rsync, zip, perl
# Optional: gh (preferred) or jq+curl with $GITHUB_TOKEN for API fallback

# ---- CONFIG ------------------------------------------------------------------
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

# ---- UI helpers --------------------------------------------------------------
color(){ printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
ok(){    color "32" "✔ $1"; }
info(){  color "36" "ℹ $1"; }
warn(){  color "33" "⚠ $1"; }
err(){   color "31" "✖ $1"; exit 1; }
require_cmd(){ command -v "$1" >/dev/null 2>&1 || err "Missing required command: $1"; }
today(){ date +"%Y-%m-%d"; }

# ---- Args --------------------------------------------------------------------
BUMP="${1:-}"
[[ -z "${BUMP}" || ! "${BUMP}" =~ ^(major|minor|patch)$ ]] && err "Usage: ./release.sh {major|minor|patch}"

# ---- Tool checks -------------------------------------------------------------
require_cmd git
require_cmd rsync
require_cmd zip
require_cmd perl
if ! command -v jq >/dev/null 2>&1; then
  warn "jq not found (only needed if falling back to API instead of gh)."
fi

# ---- Sanity ------------------------------------------------------------------
[ -f "$MAIN_FILE" ] || err "Run from the plugin root (where $MAIN_FILE exists)."
if [ "$(basename "$PWD")" != "$PLUGIN_DIR" ]; then
  warn "Directory is '$(basename "$PWD")'; expected '${PLUGIN_DIR}' for clean zip structure."
fi

# ---- Git setup (LOCAL WINS; no pulls) ---------------------------------------
if ! git rev-parse --git-dir >/dev/null 2>&1; then
  info "Initializing git repo…"
  git init
fi
git config init.defaultBranch "$DEFAULT_BRANCH" >/dev/null 2>&1 || true
if ! git rev-parse --abbrev-ref HEAD >/dev/null 2>&1; then
  git checkout -b "$DEFAULT_BRANCH"
fi
if ! git remote get-url origin >/dev/null 2>&1; then
  git remote add origin "$REMOTE_SSH"
else
  cur="$(git remote get-url origin)"
  if [ "$cur" != "$REMOTE_SSH" ]; then
    warn "Switching origin to SSH: $REMOTE_SSH"
    git remote set-url origin "$REMOTE_SSH"
  fi
fi

# ---- Read current version (robust) -------------------------------------------
# Accept lines like: "Version: 1.2.3", "* Version: 1.2.3", "// Version: 1.2.3"
LINE="$(grep -E -m1 '^[[:space:]]*(\*|//)?[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$MAIN_FILE" || true)"
if [ -n "$LINE" ]; then
  CUR_VER="$(sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/' <<< "$LINE")"
else
  CUR_VER="$(grep -Eo "define\(\s*'SOUNDWAVE_VERSION'\s*,\s*'?[0-9]+\.[0-9]+\.[0-9]+'\s*\)" "$MAIN_FILE" \
             | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | head -n1 || true)"
fi
[ -n "${CUR_VER:-}" ] || err "Could not find current version in $MAIN_FILE"

IFS='.' read -r MA MI PA <<< "$CUR_VER"
case "$BUMP" in
  major) MA=$((MA+1)); MI=0; PA=0 ;;
  minor) MI=$((MI+1)); PA=0 ;;
  patch) PA=$((PA+1)) ;;
esac
NEW_VER="${MA}.${MI}.${PA}"
TAG="v${NEW_VER}"
ok "Version bump: $CUR_VER → $NEW_VER"

# ---- CHANGELOG from last tag -------------------------------------------------
LAST_TAG="$(git describe --tags --abbrev=0 2>/dev/null || true)"
RANGE="${LAST_TAG:+${LAST_TAG}..HEAD}"
: "${RANGE:=--root}"
NOTES="$(git log $RANGE --no-merges --pretty=format:'- %s (%h)')"
[ -n "$NOTES" ] || NOTES="- Maintenance release."

CHANGELOG_SECTION=$(cat <<EOF
## ${TAG} — $(today)

${NOTES}

EOF
)

if [ -f CHANGELOG.md ]; then
  tmpcl="$(mktemp)"; { printf "%s" "$CHANGELOG_SECTION"; cat CHANGELOG.md; } > "$tmpcl"; mv "$tmpcl" CHANGELOG.md
else
  printf "# Changelog\n\n%s" "$CHANGELOG_SECTION" > CHANGELOG.md
fi
ok "CHANGELOG.md updated."

# ---- Apply version into files ------------------------------------------------
# 1) Update the first Version: line (any comment style)
perl -0777 -pe "s/(^\\h*(?:\\*|\\/\\/)?\\h*Version:\\h*)[0-9]+\\.[0-9]+\\.[0-9]+/\\1${NEW_VER}/m" -i "$MAIN_FILE"

# 2) Update define('SOUNDWAVE_VERSION','X.Y.Z') only if it exists EXACTLY ONCE
DEFINE_COUNT="$(grep -cE "define\\(\\s*'SOUNDWAVE_VERSION'" "$MAIN_FILE" || true)"
if [ "$DEFINE_COUNT" = "1" ]; then
  perl -0777 -pe "s/(define\\(\\s*'SOUNDWAVE_VERSION'\\s*,\\s*')([0-9]+\\.[0-9]+\\.[0-9]+)('\\s*\\))/\\1${NEW_VER}\\3/g" -i "$MAIN_FILE"
elif [ "$DEFINE_COUNT" = "0" ]; then
  warn "No SOUNDWAVE_VERSION define found; skipping define bump (header updated)."
else
  warn "Multiple SOUNDWAVE_VERSION defines found; skipping define bump."
fi

# 3) Optional: bump Stable tag in readme.txt if present
if [ -f "readme.txt" ]; then
  if grep -Eq '^[[:space:]]*Stable tag:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' readme.txt; then
    perl -0777 -pe "s/(Stable tag:\\s*)[0-9]+\\.[0-9]+\\.[0-9]+/\\1${NEW_VER}/g" -i readme.txt
  fi
fi

# 4) Optional: update README.md inline version references (best-effort)
if [ -f "README.md" ]; then
  perl -0777 -pe "s/\\bv[0-9]+\\.[0-9]+\\.[0-9]+\\b/v${NEW_VER}/g" -i README.md || true
fi

# ---- Validator: detect orphaned malformed fragments --------------------------
if grep -Eq "^[[:space:]]*\\.[0-9]+\\.[0-9]+\\.[0-9]+'\\);[[:space:]]*$" "$MAIN_FILE"; then
  err "Malformed version fragment detected in $MAIN_FILE (e.g., \".X.Y.Z');\"). Aborting."
fi

# ---- Commit & tag (replace if exists) ----------------------------------------
git add -A
git commit -m "Release ${TAG}" || info "Nothing to commit"
if git rev-parse "$TAG" >/dev/null 2>&1; then
  warn "Local tag $TAG exists; re-tagging."
  git tag -d "$TAG" >/dev/null 2>&1 || true
fi
git tag -a "$TAG" -m "Soundwave $TAG"

# ---- Build zip (top-level folder 'soundwave/') -------------------------------
rm -rf "$ARTIFACT_DIR" package
mkdir -p "package/${PLUGIN_DIR}" "$ARTIFACT_DIR"

RSYNC_ARGS=(); for e in "${RSYNC_EXCLUDES[@]}"; do RSYNC_ARGS+=(--exclude "$e"); done
rsync -a --delete "${RSYNC_ARGS[@]}" ./ "package/${PLUGIN_DIR}/"

( cd package && zip -r "../$ARTIFACT_DIR/${REPO_NAME}-${TAG}.zip" "${PLUGIN_DIR}" >/dev/null )
ZIP_PATH="$ARTIFACT_DIR/${REPO_NAME}-${TAG}.zip"
[ -f "$ZIP_PATH" ] || err "Failed to build zip"
ok "Built $ZIP_PATH"

# ---- Push (LOCAL IS CANONICAL) ----------------------------------------------
git branch -M "$DEFAULT_BRANCH" || true
git push -u origin "$DEFAULT_BRANCH" --force-with-lease || warn "Could not push main (check remote)"
# Replace remote tag if exists, then push fresh tag
git push origin ":refs/tags/${TAG}" >/dev/null 2>&1 || true
git push origin "$TAG" --force || warn "Could not push tag (check remote)"

# ---- Release (gh if authed; otherwise API with $GITHUB_TOKEN) ----------------
can_use_gh=false
if command -v gh >/dev/null 2>&1; then
  if gh auth status -h github.com >/dev/null 2>&1; then
    can_use_gh=true
  fi
fi

create_release_with_gh(){
  info "Creating GitHub release via gh CLI"
  gh release delete "$TAG" -R "$REPO_SLUG" --yes >/dev/null 2>&1 || true
  gh release create "$TAG" "$ZIP_PATH" \
    -R "$REPO_SLUG" \
    -t "soundwave $TAG" \
    -n "$CHANGELOG_SECTION" \
    --verify-tag
}

create_release_with_api(){
  [ -n "${GITHUB_TOKEN:-}" ] || err "GITHUB_TOKEN not set; cannot create release via API"
  command -v jq >/dev/null 2>&1 || err "jq is required for API JSON handling"
  info "Creating GitHub release via REST API"
  API="https://api.github.com/repos/${REPO_SLUG}/releases"
  # Delete existing release by tag (if present)
  REL_ID="$(curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" "${API}/tags/${TAG}" | jq -r '.id // empty' || true)"
  if [ -n "$REL_ID" ]; then
    curl -fsSL -X DELETE -H "Authorization: token ${GITHUB_TOKEN}" "${API}/${REL_ID}" >/dev/null || true
  fi
  BODY_JSON=$(jq -n --arg tag "$TAG" --arg name "soundwave $TAG" --arg body "$CHANGELOG_SECTION" \
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
  ok "GitHub release created with asset via gh."
else
  warn "gh not authenticated; using API fallback."
  create_release_with_api
  ok "GitHub release created with asset via API."
fi

ok "Release complete: $TAG"
