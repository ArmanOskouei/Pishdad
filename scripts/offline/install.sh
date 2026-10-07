#!/bin/sh
# Offline install (Linux / macOS).
#
# Loads every image that travelled in this bundle and brings the stack up.
# Nothing here reaches the network: `docker load` reads the tars next to this
# file, and the compose files it runs are pinned with `pull_policy: never`.
#
#   ./install.sh                  # load images, start backend + frontend
#   ./install.sh --backend-only   # skip the frontend
#   ./install.sh --load-only      # just load the images
#
# Ports: API_PORT / FRONTEND_PORT, defaulting to 8080 and 3100.
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BACKEND_ONLY=0
LOAD_ONLY=0
API_PORT=${API_PORT:-8080}
FRONTEND_PORT=${FRONTEND_PORT:-3100}

for arg in "$@"; do
  case "$arg" in
    --backend-only) BACKEND_ONLY=1 ;;
    --load-only)    LOAD_ONLY=1 ;;
    -h|--help)      sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

fail() { printf 'x %s\n' "$1" >&2; exit 1; }

# --- 1. Docker must exist. This is the ONE thing the bundle cannot carry. -----
command -v docker >/dev/null 2>&1 || fail "docker is not installed."
docker version --format '{{.Server.Version}}' >/dev/null 2>&1 \
  || fail "the Docker daemon is not reachable. Start it and run this again."

# --- 2. Load the images ------------------------------------------------------
IMAGES="$ROOT/offline/images"
[ -d "$IMAGES" ] || fail "offline/images/ is missing - this bundle is incomplete."

count=$(find "$IMAGES" -name '*.tar' | wc -l | tr -d ' ')
[ "$count" -gt 0 ] || fail "no .tar files in offline/images/."

echo "Loading $count image(s). This takes a minute."
for t in "$IMAGES"/*.tar; do
  echo "  $(basename "$t")"
  docker load -i "$t" >/dev/null || fail "docker load failed for $(basename "$t")"
done
echo "Images loaded."

if [ "$LOAD_ONLY" -eq 1 ]; then
  echo "--load-only given, stopping here."
  exit 0
fi

# --- 3. Unpack vendor/ -------------------------------------------------------
# It travels as a tarball rather than as loose files: composer's per-file unzip
# over a bind mount is slow enough to time out on large packages, and extracting
# one archive is far quicker than writing tens of thousands of files.
VENDOR_TAR="$ROOT/offline/vendor.tar.gz"
VENDOR_DIR="$ROOT/cms-core/backend/vendor"
if [ -f "$VENDOR_TAR" ]; then
  if [ -d "$VENDOR_DIR" ]; then
    echo "vendor/ already present, leaving it alone."
  else
    echo "Unpacking vendor/ ..."
    mkdir -p "$VENDOR_DIR"
    tar -xzf "$VENDOR_TAR" -C "$VENDOR_DIR" || fail "failed to unpack offline/vendor.tar.gz"
  fi
else
  fail "offline/vendor.tar.gz is missing - the backend would need composer install (network)."
fi

# --- 4. Bring the stacks up --------------------------------------------------
# The ports are exported with the same names the offline compose reads, so the
# published ports and the URLs printed below cannot drift apart.
export PISHDAD_WEB_PORT="$API_PORT"
export PISHDAD_FRONTEND_PORT="$FRONTEND_PORT"
# The frontend reaches the backend through the host gateway.
export INTERNAL_API_URL="http://host.docker.internal:$API_PORT/api"

echo ""
echo "Starting the backend..."
docker compose -f "$ROOT/cms-core/backend/docker-compose.offline.yml" up -d \
  || fail "docker compose up failed for the backend."

if [ "$BACKEND_ONLY" -eq 0 ]; then
  echo "Starting the frontend..."
  docker compose -f "$ROOT/cms-core/frontend/docker-compose.offline.yml" up -d \
    || fail "docker compose up failed for the frontend."
fi

echo ""
echo "Done."
echo "  Installer : http://localhost:$API_PORT/install"
if [ "$BACKEND_ONLY" -eq 0 ]; then
  echo "  Site      : http://localhost:$FRONTEND_PORT"
fi
echo ""
echo "vendor/ travelled with the bundle, so the entrypoint skips composer install."
echo "If the backend does not answer: docker logs -f cms-app"
