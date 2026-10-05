#!/usr/bin/env bash
# Runs a command with TEMP/TMP/TMPDIR redirected into this repository's .local/tmp,
# so builds and tests never write scratch files to the system drive. No global
# setting is changed, so other applications are unaffected.
#
# Usage: scripts/with-project-temp.sh npm run build
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL_TMP="$REPO_ROOT/.local/tmp"
mkdir -p "$LOCAL_TMP"

export TEMP="$LOCAL_TMP"
export TMP="$LOCAL_TMP"
export TMPDIR="$LOCAL_TMP"
export npm_config_cache="$REPO_ROOT/.local/cache/npm"

echo "[with-project-temp] TEMP/TMP -> $LOCAL_TMP"
echo "[with-project-temp] npm cache -> $npm_config_cache"

if [ "$#" -eq 0 ]; then
  echo "No command supplied. Usage: $0 <command> [args...]" >&2
  exit 64
fi

exec "$@"
