# اجرای فرانت standalone بدون بیلد (L1).
#
# چرا جدا از deploy-frontend.ps1:
# `deploy-frontend.ps1` سرور را به‌صورت child process خودش بالا می‌آورد، پس با
# ریستارت یا بستنِ آن پنجره می‌میرد و سایت می‌افتد در حالی که بیلد سالم است.
# این اسکریپت فقط سرویس را بالا می‌آورد و قابل اجرای دوباره است.
#
# دو نکته که هر دو یک بار ما را گمراه کردند:
#  1) مسیر `server.js` باید **نسبی** باشد و همراه `-WorkingDirectory` داده شود،
#     وگرنه مسیرِ دارای فاصله (`E:\Website Design\...`) می‌شکند و خطای
#     گمراه‌کنندهٔ MODULE_NOT_FOUND می‌دهد.
#  2) متغیرهای محیطی باید **قبل** از Start-Process ست شوند تا فرزند ارث ببرد.
#
# ASCII-only on purpose: non-ASCII in a message broke the parser once already.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$fe = Join-Path $root 'cms-core\frontend'
$port = if ($env:CMS_FRONT_PORT) { $env:CMS_FRONT_PORT } else { '3100' }

$server = Join-Path $fe '.next\standalone\server.js'
if (-not (Test-Path -LiteralPath $server)) {
  Write-Output "MISSING: $server - run deploy-frontend.ps1 first"
  exit 1
}

# Always refresh: after a rebuild the asset hashes change, so a stale copy makes
# the new HTML request CSS/JS that 404 → the whole page loses its styling.
# Copy the CONTENTS (`static\*`), never the folder itself — copying the folder
# into an existing folder makes PowerShell nest it as
# `standalone\.next\static\static\...` and every `/_next/static/...` 404s.
$staticSrc = Join-Path $fe '.next\static'
$staticDst = Join-Path $fe '.next\standalone\.next\static'
if (Test-Path -LiteralPath $staticSrc) {
  if (Test-Path -LiteralPath $staticDst) { Remove-Item -LiteralPath $staticDst -Recurse -Force }
  New-Item -ItemType Directory -Path $staticDst -Force | Out-Null
  Copy-Item -Path (Join-Path $staticSrc '*') -Destination $staticDst -Recurse -Force
}
$pubDst = Join-Path $fe '.next\standalone\public'
if (Test-Path -LiteralPath (Join-Path $fe 'public')) {
  if (Test-Path -LiteralPath $pubDst) { Remove-Item -LiteralPath $pubDst -Recurse -Force }
  New-Item -ItemType Directory -Path $pubDst -Force | Out-Null
  Copy-Item -Path (Join-Path $fe 'public\*') -Destination $pubDst -Recurse -Force
}

# Only THIS project's server. Other node processes on the box stay untouched.
Get-CimInstance Win32_Process -Filter "Name='node.exe'" |
  Where-Object { $_.CommandLine -like '*standalone*server.js*' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 2

# REVALIDATE_SECRET is a real shared secret, so it is NEVER written here: anyone
# with this repo could POST /api/revalidate and purge the cache of every install
# that reuses it. Order: (1) the environment (production / CI), (2) the backend's
# own git-ignored .env, which is the single source of truth. Neither -> stop,
# because a guessed value would silently break the revalidate handshake between
# the two halves of the app.
$revalidateSecret = $env:REVALIDATE_SECRET
if (-not $revalidateSecret) {
  $backendEnv = Join-Path $root 'cms-core\backend\.env'
  if (Test-Path -LiteralPath $backendEnv) {
    $hit = Select-String -LiteralPath $backendEnv -Pattern '^\s*REVALIDATE_SECRET\s*=\s*(\S+)' | Select-Object -First 1
    if ($hit) { $revalidateSecret = $hit.Matches[0].Groups[1].Value }
  }
}
if (-not $revalidateSecret) { throw 'REVALIDATE_SECRET not found. Set it in the environment or in cms-core/backend/.env.' }
$env:NEXT_PUBLIC_API_URL = 'http://localhost:8080/api'
$env:INTERNAL_API_URL = 'http://localhost:8080/api'
$env:REVALIDATE_SECRET = $revalidateSecret
$env:PORT = $port
$env:HOSTNAME = '0.0.0.0'

# Project-local log dir: $env:TEMP is an 8.3 short path that does not resolve
# on this box (same trap AGENTS.md M7 documents).
$logDir = Join-Path $root '.local\build-logs'
if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
$log = Join-Path $logDir 'fe-serve.log'
$err = Join-Path $logDir 'fe-serve.err.log'

$p = Start-Process -FilePath 'node' -ArgumentList '.next\standalone\server.js' `
  -WorkingDirectory $fe -WindowStyle Hidden -PassThru `
  -RedirectStandardOutput $log -RedirectStandardError $err

Write-Output "started pid=$($p.Id) port=$port"

Start-Sleep -Seconds 8
$fontCode = & curl.exe -s -o NUL -w '%{http_code}' --max-time 20 "http://localhost:$port/fonts/Vazirmatn-Regular.woff2"
$siteCode = & curl.exe -s -o NUL -w '%{http_code}' --max-time 25 "http://localhost:$port/"
$loginCode = & curl.exe -s -o NUL -w '%{http_code}' --max-time 20 "http://localhost:$port/admin/login"
Write-Output "RESULT: font=$fontCode site=$siteCode login=$loginCode"

if ($siteCode -ne '200' -or $fontCode -ne '200') {
  Write-Output '--- stderr tail ---'
  if (Test-Path -LiteralPath $err) { Get-Content -LiteralPath $err -Tail 12 }
  exit 1
}
exit 0
