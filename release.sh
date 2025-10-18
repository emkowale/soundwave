#!/usr/bin/env bash
# Soundwave release script — all-in-one: bump, tag, zip (soundwave/), push, GitHub release.
# Requirements: bash, git, zip, rsync; either GitHub CLI `gh` OR env var GITHUB_TOKEN for API fallback.

set -euo pipefail

# ==== CONFIG ==================================================================
OWNER="emkowale"
REPO="soundwave"
REMOTE_SSH="git@github.com:${OWNER}/${REPO}.git"
MAIN_FILE="soundwave.php"
PLUGIN_DIR_NAME="soundwave"                # must be this inside the zip
ZIP_BASENAME="${REPO}"                     # "soundwave"
DEFAULT_BRANCH="main"                      # change if you use something else

# Exclusions when packaging the zip
RSYNC_EXCLUDES=(
  ".git"
  ".github"
  ".gitignore"
  ".gitattributes"
  ".DS_Store"
  "*.zip"
  "node_modules"
  "vendor/*/.git"
  "tests"
  "Test"
  "tmp"
  "dist"
  ".idea"
  ".vscode"
  "release.sh"
)

# ==== UI HELPERS ==============================================================
C_RESET=$'\033[0m'; C_CYAN=$'\033[1;36m'; C_YEL=$'\033[1;33m'; C_RED=$'\033[1;31m'; C_GRN=$'\033[1;32m'
step(){ printf "${C_CYAN}🔷 %s${C_RESET}\n" "$*"; }
ok(){   printf "${C_GRN}✅ %s${C_RESET}\n" "$*"; }
warn(){ printf "${C_YEL}⚠️  %s${C_RESET}\n" "$*"; }
die(){  printf "${C_RED}🛑 %s${C_RESET}\n" "$*" >&2; exit 1; }

# ==== ARG CHECK ===============================================================
BUMP_KIND="${1:-}"
if [[ -z "${BUMP_KIND}" || ! "${BUMP_KIND}" =~ ^(major|minor|patch)$ ]]; then
  die "Usage: ./release.sh {major|minor|patch}"
fi

# ==== GUARDS ==================================================================
[[ -f "${MAIN_FILE}" ]] || die "Could not find ${MAIN_FILE}. Run this from the plugin root."

# ==== GIT SETUP ===============================================================
step "Checking git repository state…"
if [[ ! -d ".git" ]]; then
  warn "No .git directory found. Initializing repo and linking to ${REMOTE_SSH}…"
  git init
  git remote add origin "${REMOTE_SSH}"
  # Try to pull if remote exists; otherwise continue with empty repo
  if git ls-remote --exit-code origin &>/dev/null; then
    # Detect default branch if possible
    DEFAULT_BRANCH=$(git ls-remote --symref origin HEAD 2>/dev/null | awk -F'/' '/^ref:/ {print $NF}')
    [[ -z "${DEFAULT_BRANCH}" ]] && DEFAULT_BRANCH="main"
    git fetch origin
    if git rev-parse --verify "${DEFAULT_BRANCH}" &>/dev/null; then
      git checkout "${DEFAULT_BRANCH}"
    else
      git checkout -b "${DEFAULT_BRANCH}"
      git pull origin "${DEFAULT_BRANCH}" || true
    fi
  else
    warn "Remote origin not reachable yet. Proceeding; first push will create ${DEFAULT_BRANCH}."
    git checkout -b "${DEFAULT_BRANCH}"
  fi
else
  # Ensure remote uses SSH to satisfy your preference
  CURRENT_REMOTE="$(git remote get-url origin || true)"
  if [[ -z "${CURRENT_REMOTE}" ]]; then
    git remote add origin "${REMOTE_SSH}"
    ok "Added origin ${REMOTE_SSH}"
  elif [[ "${CURRENT_REMOTE}" != "${REMOTE_SSH}" ]]; then
    warn "Remote origin is '${CURRENT_REMOTE}', switching to SSH '${REMOTE_SSH}'…"
    git remote set-url origin "${REMOTE_SSH}"
  fi
  # Ensure we're on the default branch
  CUR_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
  if [[ "${CUR_BRANCH}" != "${DEFAULT_BRANCH}" ]]; then
    warn "Currently on '${CUR_BRANCH}'. Switching to '${DEFAULT_BRANCH}'…"
    git checkout "${DEFAULT_BRANCH}" || git checkout -b "${DEFAULT_BRANCH}"
  fi
  git pull --rebase origin "${DEFAULT_BRANCH}" || true
fi
ok "Git repository ready."

# ==== VERSION DISCOVERY =======================================================
step "Reading current version from ${MAIN_FILE}…"
# Try to find a 'Version: x.y.z' line in the plugin header first
HEADER_VER_LINE="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' -m1 "${MAIN_FILE}" || true)"
if [[ -n "${HEADER_VER_LINE}" ]]; then
  CURRENT_VERSION="$(sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/' <<< "${HEADER_VER_LINE}")"
else
  # Fallback: look for a define like define('SOUNDWAVE_VERSION','x.y.z');
  CURRENT_VERSION="$(grep -Eo "SOUNDWAVE_VERSION[\"')[:space:]]*[,']+[[:space:]]*'?[0-9]+\.[0-9]+\.[0-9]+'?" "${MAIN_FILE}" \
    | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | head -n1 || true)"
fi
[[ -n "${CURRENT_VERSION}" ]] || die "Could not locate current version in ${MAIN_FILE}."

ok "Current version: ${CURRENT_VERSION}"

# ==== SEMVER BUMP =============================================================
IFS='.' read -r MA MI PA <<< "${CURRENT_VERSION}"
case "${BUMP_KIND}" in
  major) ((MA++)); MI=0; PA=0 ;;
  minor) ((MI++)); PA=0 ;;
  patch) ((PA++)) ;;
esac
NEW_VERSION="${MA}.${MI}.${PA}"
ok "Bumping ${BUMP_KIND}: ${CURRENT_VERSION} → ${NEW_VERSION}"

# ==== APPLY VERSION CHANGES ===================================================
step "Applying version ${NEW_VERSION} to source files…"

# 1) Update 'Version: x.y.z' lines in headers (plural-safe)
perl -0777 -pe "s/(\\*\\s*Version:\\s*)[0-9]+\\.[0-9]+\\.[0-9]+/\\1${NEW_VERSION}/g" -i "${MAIN_FILE}"

# 2) Update any define('SOUNDWAVE_VERSION','x.y.z') if present
if grep -Eq "define\(\s*'SOUNDWAVE_VERSION'\s*,\s*'?[0-9]+\.[0-9]+\.[0-9]+'\s*\)" "${MAIN_FILE}"; then
  perl -0777 -pe "s/(define\\(\\s*'SOUNDWAVE_VERSION'\\s*,\\s*')([0-9]+\\.[0-9]+\\.[0-9]+)('\\s*\\))/\\1${NEW_VERSION}\\3/g" -i "${MAIN_FILE}"
fi

# 3) Optional: update readme.txt Stable tag if present
if [[ -f "readme.txt" ]]; then
  if grep -Eq '^[[:space:]]*Stable tag:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' readme.txt; then
    perl -0777 -pe "s/(Stable tag:\\s*)[0-9]+\\.[0-9]+\\.[0-9]+/\\1${NEW_VERSION}/g" -i readme.txt
  fi
fi

# 4) Optional: bump README.md version badges or mentions (soft)
if [[ -f "README.md" ]]; then
  perl -0777 -pe "s/\\bv[0-9]+\\.[0-9]+\\.[0-9]+\\b/v${NEW_VERSION}/g" -i README.md || true
fi

# ==== COMMIT & TAG ============================================================
step "Committing version bump…"
# Add all (safe for your repo; adjust if needed)
git add -A
if ! git diff --cached --quiet; then
  git commit -m "Release v${NEW_VERSION}"
else
  warn "No changes staged; proceeding."
fi

# Create tag (idempotent)
if git rev-parse "v${NEW_VERSION}" >/dev/null 2>&1; then
  warn "Tag v${NEW_VERSION} already exists; reusing."
else
  git tag -a "v${NEW_VERSION}" -m "Soundwave v${NEW_VERSION}"
fi

step "Pushing branch and tags to origin…"
git push origin "${DEFAULT_BRANCH}"
git push origin "v${NEW_VERSION}"

# ==== BUILD ZIP WITH TOP-LEVEL 'soundwave' FOLDER ============================
step "Packaging zip with top-level folder '${PLUGIN_DIR_NAME}'…"
TMPDIR="$(mktemp -d)"
mkdir -p "${TMPDIR}/${PLUGIN_DIR_NAME}"

# Compose rsync exclude args
RSYNC_ARGS=()
for e in "${RSYNC_EXCLUDES[@]}"; do RSYNC_ARGS+=(--exclude "${e}"); done

rsync -a . "${TMPDIR}/${PLUGIN_DIR_NAME}/" "${RSYNC_ARGS[@]}"

(
  cd "${TMPDIR}"
  ZIP_NAME="${ZIP_BASENAME}-v${NEW_VERSION}.zip"
  zip -r "${ZIP_NAME}" "${PLUGIN_DIR_NAME}" >/dev/null
  mv "${ZIP_NAME}" -t "$(pwd -P)/"
)

FINAL_ZIP="${TMPDIR}/${ZIP_BASENAME}-v${NEW_VERSION}.zip"
[[ -f "${FINAL_ZIP}" ]] || die "Zip not created."

ok "Built $(basename "${FINAL_ZIP}")"

# ==== CREATE GITHUB RELEASE & UPLOAD ASSET ===================================
create_release_with_gh() {
  step "Creating GitHub release via gh…"
  gh release create "v${NEW_VERSION}" "${FINAL_ZIP}" \
     --title "soundwave v${NEW_VERSION}" \
     --notes "Automated release of soundwave v${NEW_VERSION}." \
     --repo "${OWNER}/${REPO}" \
     --verify-tag || return 1
  return 0
}

create_release_with_api() {
  [[ -n "${GITHUB_TOKEN:-}" ]] || { warn "GITHUB_TOKEN not set; cannot use API fallback."; return 1; }
  step "Creating GitHub release via GitHub API…"
  API="https://api.github.com/repos/${OWNER}/${REPO}/releases"
  UPLOAD="https://uploads.github.com/repos/${OWNER}/${REPO}/releases"
  DATA=$(jq -n --arg tag "v${NEW_VERSION}" --arg name "soundwave v${NEW_VERSION}" --arg body "Automated release of soundwave v${NEW_VERSION}." '{tag_name:$tag, name:$name, body:$body, draft:false, prerelease:false}')
  RESP=$(curl -fsSL -H "Authorization: token ${GITHUB_TOKEN}" -H "Accept: application/vnd.github+json" -d "${DATA}" "${API}")
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
  warn "gh not found; trying API fallback (requires \$GITHUB_TOKEN)…"
  create_release_with_api || die "GitHub API release failed (set GITHUB_TOKEN or install gh)."
  ok "GitHub release created with asset via API."
fi

# ==== DONE ====================================================================
ok "All done! Release v${NEW_VERSION} pushed, tagged, and uploaded."
printf "\nZip: %s\n" "${FINAL_ZIP}"
