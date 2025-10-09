#!/usr/bin/env bash
set -euo pipefail
BUMP=${1:-}
if [[ -z "$BUMP" ]]; then echo "Usage: ./release.sh {major|minor|patch}"; exit 1; fi
FILE="soundwave/soundwave.php"
CURR=$(grep -E "^Version:" -n "$FILE" | head -n1 | sed -E 's/.*Version: *([0-9]+\.[0-9]+\.[0-9]+).*/\1/')
IFS='.' read -r MAJ MIN PAT <<< "$CURR"
case "$BUMP" in
  major) MAJ=$((MAJ+1)); MIN=0; PAT=0;;
  minor) MIN=$((MIN+1)); PAT=0;;
  patch) PAT=$((PAT+1));;
  *) echo "Invalid bump"; exit 1;;
esac
NEW="${MAJ}.${MIN}.${PAT}"
sed -i.bak -E "s/(^Version: *).*/\1${NEW}/" "$FILE"
sed -i.bak -E "s/(define\('SOUNDWAVE_VERSION', *')[0-9]+\.[0-9]+\.[0-9]+('\);)/\1${NEW}\2/" "$FILE"
rm -f soundwave/soundwave.php.bak
( cd "$(dirname "$FILE")/.." && zip -r "soundwave-v${NEW}.zip" "soundwave" >/dev/null )
echo "Built soundwave-v${NEW}.zip"
