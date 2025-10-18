#!/usr/bin/env bash
# Soundwave release script — local is the source of truth
# Bump {major|minor|patch}, regenerate CHANGELOG.md, tag, zip (soundwave/), push, GitHub release.
# Requires: bash, git, zip, rsync, perl, jq; optional: gh (preferred for release)

set -euo pipefail

# ==== CONFIG ==================================================================
OWNER="emkowale"
REPO="soundwave"
REMOTE_SSH="git@github.com:${OWNER}/${REPO}.git"
MAIN_FILE="soundwave.php"
PLUGIN_DIR_NAME="soundwave"
ZIP_BASENAME="${REPO}"
DEFAULT_BRANCH="main"

RSYNC_EXCLUDES=(
  ".git" ".github" ".gitignore" ".gitattributes" ".DS_Store" "*.zip"
  "node_modules" "vendor/*/.git" "tests" "Test" "tmp" "dist" ".idea" ".vscode"
  "release.sh"
)

# ==== UI HELPERS ==============================================================
C_RESET=$'\033[0m'; C_CYAN=$'\033[1;36m'; C_YEL=$'\033[1;33m'; C_RED=$'\033[1;31m'; C_GRN=$'\033[1;32m'
step(){ printf "${C_CYAN}🔷 %s${C_RESET}\n" "$*"; }
ok(){   printf "${C_GRN}✅ %s${C_RESET}\n" "$*"; }
warn(){ printf "${C_YEL}⚠️  %s${C_RESET}\n" "$*"; }
die(){  printf "${C_RED}🛑 %s${C_RESET}\n" "$*" >&2; exit 1; }

today(){ date +"%Y-%m-%d"; }

# ==== ARG CHECK ===============================================================
BUMP_KIND="${1:-}"
[[ -z "${BUMP_KIND}" || ! "${BUMP_KIND}" =~ ^(major|minor|patch)$ ]] && die "Usage: ./release.sh {major|minor|patch}"

# ==== GIT SETUP (LOCAL WINS) =================================================
step "Ensuring git repo exists and origin is SSH…"
[[ -d .git ]] || git init
git config init.defaultBranch "${DEFAULT_BRANCH}" >/dev/null 2>&1 || true
if ! git rev-parse --abbrev-ref HEAD >/dev/null 2>&1; then
  git checkout -b "${DEFAULT_BRANCH}"
fi
if ! git remote get-url origin >/dev/null 2>&1; then
  git remote add origin "${REMOTE_SSH}"
else
  CUR="$(git remote get-url origin)"
  if [[ "${CUR}" != "${REMOTE_SSH}" ]]; then
    warn "Switching origin to SSH ${REMOTE_SSH}"
    git remote set-url origin "${REMOTE_SSH}"
  fi
fi
# No pulls: local is canonical.

# ==== VERSION DISCOVERY =======================================================
step "Reading current version from ${MAIN_FILE}…"
[[ -f "${MAIN_FILE}" ]] || die "Missing ${MAIN_FILE}."
HEADER_VER_LINE="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' -m1 "${MAIN_FILE}" || true)"
if [[ -n "${HEADER_VER_LINE}" ]]; then
  CURRENT_VERSION="$(sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/' <<< "${HEADER_VER_LINE}")"
else
  CURRENT_VERSION="$(grep -Eo "SOUNDWAVE_VERSION[\"')[:space:]]*[,']+[[:space:]]*'?[0-9]+\.[0-9]+\.[0-9]+'?" "${MAIN_FILE}" | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | head -n1 || true)"
fi
[[ -n "${CURRENT_VERSION}" ]] || die "Could not find current version in ${MAIN_FILE}."
ok "Current version: ${CURRENT_VERSION}"

IFS='.' read -r MA MI PA <<< "${CURRENT_VERSION}"
case "${BUMP_KIND}" in
  major) ((MA++)); MI=0; PA=0 ;;
  minor) ((MI++)); PA=0 ;;
  patch) ((PA++)) ;;
esac
NEW_VERSION="${MA}.${MI}.${PA}"
RELEASE_TAG="v${NEW_VERSION}"
ok "Bumping ${BUMP_KIND}: ${CURRENT_VERSION} → ${NEW_VERSION}"

# ==== CHANGELOG (since last tag) =============================================
step "Generating changelog since last tag…"
LAST_TAG="$(git describe --tags --abbrev=0 2>/dev/null || true)"
if [[ -n "${LAST_TAG}" ]]; then
  RANGE="${LAST_TAG}..HEAD"
else
  RANGE="--root"
fi

# Collect commits (skip merge noise but keep merge titles if you like: remove --no-merges to include)
NOTES="$(git log ${RANGE} --no-merges --pretty=format:'- %s (%h)')"
if [[ -z "${NOTES}" ]]; then
  NOTES="- Maintenance release."
fi

CHANGELOG_SECTION=$(
  cat <<EOF
## ${RELEASE_TAG} — $(today)

${NOTES}

EOF
)

# Prepend (or create) CHANGELOG.md
if [[ -f CHANGELOG.md ]]; then
  TMPCL="$(mktemp)"
  {
    echo "${CHANGELOG_SECTION}"
    cat CHANGELOG.md
  } > "${TMPCL}"
  mv "${TMPCL}" CHANGELOG.md
else
  printf "# Changelog\n\n%s" "${CHANGELOG_SECTION}" > CHANGELOG.md
fi
ok "CHANGELOG.md updated."

# ==== APPLY VERSION CHANGES ===================================================
step "Applying version ${NEW_VERSION} to source…"
perl -0777 -pe "s/(\\*\\s*Version:\\s*)[0-9]+\\.[0-9]+\\.[0-9]+/\\1${NEW_VERSION}/g" -i "${MAIN_FILE}"

if grep -Eq "define\(\s*'SOUNDWAVE_VERSION'\s*,\s*'?[0-9]+\.[0-9]+\.[0-9]+'\s*\)" "${MAIN_FILE}"; then
  perl -0777 -pe "s/(define\\(\\s*'SOUNDWAVE_VERSION'\\s*,\\s*')([0-9]+\\.[0-9]+\\.[0-9]+)('\\s*\\))/\\1${NEW_VERSION}\\3/g" -i "${MAIN_FILE}"
fi

if [[ -f "readme.txt" ]]; then
  if grep -Eq '^[[:space:]]*Stable tag:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' readme.txt; then
    perl -0777 -pe "s/(Stable tag:\\s*)[0-9]+\\.[0-9]+\\.[0-9]+/\\1${NEW_VERSION}/g" -i readme.txt
  fi
fi

if [[ -f "README.md" ]]; then
  perl -0777 -pe "s/\\bv[0-9]+\\.[0-9]+\\.[0-9]+\\b/${RELEASE_TAG}/g" -i README.md || true
fi

# ==== COMMIT & TAG (replace if exists) =======================================
step "Committing version bump + changelog…"
git add -A
if ! git diff --cached --quiet; then
  git commit -m "Release ${RELEASE_TAG}"
else
  warn "No changes to commit."
fi

# Move local tag if exists; remote will be replaced later
if git rev-parse "${RELEASE_TAG}" >/dev/null 2>&1; then
  warn "Local tag ${RELEASE_TAG} exists; re-tagging."
  git tag -d "${RELEASE_TAG}" >/dev/null 2>&1 || true
fi
git tag -a "${RELEASE_TAG}" -m "Soundwave ${RELEASE_TAG}"

# ==== BUILD ZIP WITH TOP-LEVEL 'soundwave' ===================================
step "Packaging zip with top-level folder '${PLUGIN_DIR_NAME}'…"
TMPDIR="$(mktemp -d)"
mkdir -p "${TMPDIR}/${PLUGIN_DIR_NAME}"

RSYNC_ARGS=()
for e in "${RSYNC_EXCLUDES[@]}"; do RSYNC_ARGS+=(--exclude "${e}"); done
rsync -a . "${TMPDIR}/${PLUGIN_DIR_NAME}/" "${RSYNC_ARGS[@]}"

(
  cd "${TMPDIR}"
  ZIP_NAME="${ZIP_BASENAME}-${RELEASE_TAG}.zip"
  zip -r "${ZIP_NAME}" "${PLUGIN_DIR_NAME}" >/dev/null
)
FINAL_ZIP="${TMPDIR}/${ZIP_BASENAME}-${RELEASE_TAG}.zip"
[[ -f "${FINAL_ZIP}" ]] || die "Zip not created."
ok "Built $(basename "${FINAL_ZIP}")"

# ==== PUBLISH (local is canonical) ===========================================
step "Pushing ${DEFAULT_BRANCH} (force-with-lease)…"
git branch -M "${DEFAULT_BRANCH}"
git push -u origin "${DEFAULT_BRANCH}" --force-with-lease

step "Replacing remote tag if exists…"
git push origin ":refs/tags/${RELEASE_TAG}" >/dev/null 2>&1 || true
git push origin "${RELEASE_TAG}" --force

# ==== CREATE GITHUB RELEASE (CHANGELOG notes) =================================
create_release_with_gh() {
  step "Creating GitHub release via gh…"
  gh release delete "${RELEASE_TAG}" --yes --repo "${OWNER}/${REPO}" >/dev/null 2>&1 || true
  # Use the freshly generated section as release notes
  gh release create "${RELEASE_TAG}" "${FINAL_ZIP}" \
    --title "soundwave ${RELEASE_TAG}" \
    --notes "${CHANGELOG_SECTION}" \
    --repo "${OWNER}/${REPO}" \
    --verify-tag
}

create_release_with_api() {
  [[ -n "${GITHUB_TOKEN:-}" ]] || { warn "GITHUB_TOKEN not set; cannot use API fallback."; return 1; }
  step "Creating GitHub release via API…"
  API="https://api.github.com/repos/${OWNER}/${REPO}/releases"
  # Delete existing release (if any)
  REL_ID="$(curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" "${API}/tags/${RELEASE_TAG}" 2>/dev/null | jq -r '.id // empty')"
  if [[ -n "${REL_ID}" ]]; then
    curl -fsSL -X DELETE -H "Authorization: token ${GITHUB_TOKEN}" "${API}/${REL_ID}" >/dev/null || true
  fi
  BODY_JSON=$(jq -n --arg tag "${RELEASE_TAG}" --arg name "soundwave ${RELEASE_TAG}" --arg body "${CHANGELOG_SECTION}" \
    '{tag_name:$tag, name:$name, body:$body, draft:false, prerelease:false}')
  RESP=$(curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" -H "Accept: application/vnd.github+json" -d "${BODY_JSON}" "${API}")
  UPLOAD_URL=$(jq -r '.upload_url' <<< "${RESP}" | sed 's/{?name,label}//')
  [[ -n "${UPLOAD_URL}" && "${UPLOAD_URL}" != "null" ]] || return 1
  FNAME="$(basename "${FINAL_ZIP}")"
  step "Uploading asset ${FNAME}…"
  curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" -H "Content-Type: application/zip" \
    --data-binary @"${FINAL_ZIP}" \
    "${UPLOAD_URL}?name=${FNAME}" >/dev/null
}

if command -v gh >/dev/null 2>&1; then
  create_release_with_gh || die "gh release failed."
  ok "GitHub release created with asset via gh."
else
  warn "gh not found; using API fallback."
  create_release_with_api || die "GitHub API release failed. Install gh or set GITHUB_TOKEN."
  ok "GitHub release created with asset via API."
fi

ok "All done. ${RELEASE_TAG} pushed (local is source of truth), tagged, and released."
printf "\nZip: %s\n" "${FINAL_ZIP}"
