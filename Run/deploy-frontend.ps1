# استقرار فرانت (standalone) — استفاده: powershell -ExecutionPolicy Bypass -File Run\deploy-frontend.ps1
# ریشه مشکل قبلی: پوشه public (شامل فونت‌های محلی) در بیلد standalone کپی نمی‌شد
# و فونت Vazirmatn ۴۰۴ می‌داد ⇒ عنوان هدر و جستجو «ناخوانا» می‌شد.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$fe = Join-Path $root 'cms-core\frontend'
$port = if ($env:CMS_FRONT_PORT) { $env:CMS_FRONT_PORT } else { '3101' }

Write-Output "[1/5] killing old standalone ..."
Get-CimInstance Win32_Process -Filter "Name='node.exe'" |
  Where-Object { $_.CommandLine -like '*standalone*server.js*' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 3

Write-Output "[2/5] clearing webpack cache (avoids ENOENT pack rename) ..."
$wcache = Join-Path $fe '.next\cache\webpack'
if (Test-Path -LiteralPath $wcache) { Remove-Item -LiteralPath $wcache -Recurse -Force -ErrorAction SilentlyContinue }

Write-Output "[3/5] building ..."
Push-Location $fe
npm run build
if ($LASTEXITCODE -ne 0) { Pop-Location; throw "build failed" }

Write-Output "[4/5] copying .next/static and public -> standalone ..."
$sw = Join-Path $fe '.next\standalone\.next\static'
if (Test-Path -LiteralPath $sw) { Remove-Item -LiteralPath $sw -Recurse -Force }
Copy-Item -Path (Join-Path $fe '.next\static') -Destination $sw -Recurse -Force

$pubSrc = Join-Path $fe 'public'
$pubDst = Join-Path $fe '.next\standalone\public'
if (Test-Path -LiteralPath $pubSrc) {
  New-Item -ItemType Directory -Path $pubDst -Force | Out-Null
  Copy-Item -Path (Join-Path $pubSrc '*') -Destination $pubDst -Recurse -Force
  $n = (Get-ChildItem -LiteralPath $pubDst -Recurse -File | Measure-Object).Count
  Write-Output "      public files copied: $n"
}

Write-Output "[5/5] starting on port $port ..."
Pop-Location
$env:NEXT_PUBLIC_API_URL = 'http://localhost:8080/api'
$env:INTERNAL_API_URL = 'http://localhost:8080/api'
$env:REVALIDATE_SECRET = 'e39f4ba00cc6eb04534f15e757ad5964013aecd91177b623'
$env:PORT = $port
$env:HOSTNAME = '0.0.0.0'
$log = Join-Path $env:TEMP 'opencode\front.log'
$err = Join-Path $env:TEMP 'opencode\front-err.log'
Start-Process -FilePath 'node' -ArgumentList '.next\standalone\server.js' `
  -WorkingDirectory $fe -WindowStyle Hidden `
  -RedirectStandardOutput $log -RedirectStandardError $err

Start-Sleep -Seconds 8
$font = 'ok'
$fontCode = & curl.exe -s -o NUL -w '%{http_code}' --max-time 20 "http://localhost:$port/fonts/Vazirmatn-Regular.woff2"
if ($fontCode -ne '200') { $font = "HTTP $fontCode" }
$siteCode = & curl.exe -s -o NUL -w '%{http_code}' --max-time 25 "http://localhost:$port/"
Write-Output "RESULT: font=$font site=$siteCode"
if ($font -ne 'ok' -or $siteCode -ne '200') { Write-Output "WARNING: check $err" }
