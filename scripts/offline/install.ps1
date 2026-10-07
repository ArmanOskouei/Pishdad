# Offline install (Windows / PowerShell).
#
# Loads every image that travelled in this bundle and brings the stack up.
# Nothing here reaches the network: `docker load` reads the tars next to this
# file, and the compose files it runs are pinned with `pull_policy: never`.
#
#   .\install.ps1                 # load images, start backend + frontend
#   .\install.ps1 -BackendOnly    # skip the frontend
#   .\install.ps1 -LoadOnly       # just load the images

param(
    [switch]$BackendOnly,
    [switch]$LoadOnly,
    [int]$ApiPort = 8080,
    [int]$FrontendPort = 3100
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

function Fail($msg) { Write-Host "x $msg" -ForegroundColor Red; exit 1 }

# --- 1. Docker must exist. This is the ONE thing the bundle cannot carry. -----
try { docker version --format '{{.Server.Version}}' | Out-Null }
catch { Fail "Docker is not running or not installed. Start Docker Desktop and run this again." }

# --- 2. Load the images ------------------------------------------------------
$images = Join-Path $root 'offline\images'
if (-not (Test-Path $images)) { Fail "offline\images\ is missing - this bundle is incomplete." }

$tars = Get-ChildItem $images -Filter *.tar | Sort-Object Name
if ($tars.Count -eq 0) { Fail "no .tar files in offline\images\." }

Write-Host "Loading $($tars.Count) image(s). This takes a minute." -ForegroundColor Cyan
foreach ($t in $tars) {
    Write-Host "  $($t.Name)"
    docker load -i $t.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail "docker load failed for $($t.Name)" }
}
Write-Host "Images loaded." -ForegroundColor Green

if ($LoadOnly) { Write-Host "`n-LoadOnly given, stopping here."; exit 0 }

# --- 3. Unpack vendor/ -------------------------------------------------------
# It travels as a tarball rather than as loose files: composer's per-file unzip
# over a Windows bind mount is slow enough to time out on large packages, and
# extracting one archive is far quicker than writing tens of thousands of files
# one at a time.
$vendorTar = Join-Path $root 'offline\vendor.tar.gz'
$vendorDir = Join-Path $root 'cms-core\backend\vendor'
if (Test-Path $vendorTar) {
    if (Test-Path $vendorDir) {
        Write-Host "vendor/ already present, leaving it alone." -ForegroundColor DarkGray
    } else {
        Write-Host "Unpacking vendor/ ..." -ForegroundColor Cyan
        New-Item -ItemType Directory -Force -Path $vendorDir | Out-Null
        # Windows 10+ ships bsdtar; -xzf handles the gzip stream.
        tar -xzf $vendorTar -C $vendorDir
        if ($LASTEXITCODE -ne 0) { Fail "failed to unpack offline\vendor.tar.gz" }
    }
} else {
    Fail "offline\vendor.tar.gz is missing - the backend would need composer install (network)."
}

# --- 4. Bring the stacks up --------------------------------------------------
# Ports are passed through the same variables the offline compose reads, so the
# published ports and the URLs printed below cannot drift apart.
$env:PISHDAD_WEB_PORT = "$ApiPort"
if (Test-Path (Join-Path $root 'cms-core\frontend\docker-compose.offline.yml')) {
    $env:PISHDAD_FRONTEND_PORT = "$FrontendPort"
    # The frontend reaches the backend through the host gateway, so it needs the
    # port the API is actually published on.
    $env:INTERNAL_API_URL = "http://host.docker.internal:$ApiPort/api"
}

Write-Host "`nStarting the backend..." -ForegroundColor Cyan
docker compose -f (Join-Path $root 'cms-core\backend\docker-compose.offline.yml') up -d
if ($LASTEXITCODE -ne 0) { Fail "docker compose up failed for the backend." }

if (-not $BackendOnly) {
    Write-Host "Starting the frontend..." -ForegroundColor Cyan
    docker compose -f (Join-Path $root 'cms-core\frontend\docker-compose.offline.yml') up -d
    if ($LASTEXITCODE -ne 0) { Fail "docker compose up failed for the frontend." }
}

Write-Host "`nDone." -ForegroundColor Green
Write-Host "  Installer : http://localhost:$ApiPort/install"
if (-not $BackendOnly) { Write-Host "  Site      : http://localhost:$FrontendPort" }
Write-Host ""
Write-Host "vendor/ travelled with the bundle, so the entrypoint skips composer install." -ForegroundColor DarkGray
Write-Host "If the backend does not answer, look at:" -ForegroundColor DarkGray
Write-Host "  docker logs -f cms-app" -ForegroundColor DarkGray
