# Detached frontend build + hard-timeout poller.
# Why this file exists: piping deploy-frontend.ps1 into Select-Object never returns,
# because its Start-Process child holds the console handle (no EOF). So the parent
# is launched detached here and only polled, never piped.
# ASCII-only on purpose: a non-ASCII char in a message broke the parser once already.
param(
  [int]$TimeoutSec = 900
)

$ErrorActionPreference = 'Stop'
$root = 'E:\Website Design\ArmanOskouei.ir'
# Project-local log dir on purpose. $env:TEMP on this box points at an 8.3 short
# path that does not resolve (same trap AGENTS.md M7 documents), and .local/ is
# already gitignored - so this satisfies both rules.
$tmpDir = Join-Path $root '.local\build-logs'
if (-not (Test-Path -LiteralPath $tmpDir)) { New-Item -ItemType Directory -Path $tmpDir -Force | Out-Null }
# Unique per run: a leftover log from an interrupted run stays locked by its
# writer, and deleting it would fail the whole script before anything ran.
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$log  = Join-Path $tmpDir "fe-build-$stamp.out.log"
$err  = Join-Path $tmpDir "fe-build-$stamp.err.log"

# Kill only THIS project's standalone server. Other node processes on the box
# (MCP servers, an unrelated project) must stay untouched.
Get-CimInstance Win32_Process -Filter "Name='node.exe'" |
  Where-Object { $_.CommandLine -like '*standalone*server.js*' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

$script = Join-Path $root 'Run\deploy-frontend.ps1'

$p = Start-Process -FilePath 'powershell.exe' `
  -ArgumentList '-ExecutionPolicy', 'Bypass', '-NoProfile', '-File', ('"' + $script + '"') `
  -WorkingDirectory $root -WindowStyle Hidden -PassThru `
  -RedirectStandardOutput $log -RedirectStandardError $err

Write-Output ("started pid=" + $p.Id + " timeout=" + $TimeoutSec + "s")

$deadline = (Get-Date).AddSeconds($TimeoutSec)
$lastLine = ''

# ⚠️ چرا فقط منتظرِ خروج نیستیم:
#
# `deploy-frontend.ps1` در گامِ پنجم `next start` را **foreground** اجرا
# می‌کند — که یک سرور دائمی است، پس پروسه هرگز `HasExited` نمی‌شود.
# نسخهٔ اول این اسکریپت فقط روی `$p.HasExited` حلقه می‌زد، پس بعد از
# بیلدِ کاملاً موفق تا پایان `TimeoutSec` (یعنی ۱۲ دقیقه) معلق می‌ماند و
# `exited code=` خالی می‌نوشت. از بیرون شبیه «گیر کردن» بود.
#
# پس ملاکِ پایان، **رسیدن به سطر تأییدِ سرویس** است نه خروجِ پروسه.
$succeeded = $false

while ((Get-Date) -lt $deadline) {
  if (Test-Path -LiteralPath $log) {
    $tail = Get-Content -LiteralPath $log -Tail 1 -ErrorAction SilentlyContinue
    if ($tail -and $tail -ne $lastLine) { $lastLine = $tail; Write-Output ("  progress: " + $tail) }
    if ($tail -like 'RESULT:*') { $succeeded = $true; break }
  }

  # اگر واقعاً کرش کرد، زودتر بفهمیم.
  if ($p.HasExited) { break }

  Start-Sleep -Seconds 10
}

if ($succeeded) {
  Write-Output 'OK - the server answered and the build is serving.'
  Write-Output '--- stdout tail ---'
  if (Test-Path -LiteralPath $log) { Get-Content -LiteralPath $log -Tail 10 }
  if ((Test-Path -LiteralPath $err) -and (Get-Item -LiteralPath $err).Length -gt 0) {
    Write-Output '--- stderr tail ---'
    Get-Content -LiteralPath $err -Tail 10
  }
  # ⚠️ عمداً `deploy-frontend.ps1` و سرورش را زنده می‌گذاریم؛ کارِ همین
  # اسکریپت اجرای سرور است نه بستنِ آن.
  exit 0
}

if (-not $p.HasExited) {
  Write-Output ("TIMEOUT after " + $TimeoutSec + "s - killing build (retry is safe)")
  Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
  Get-CimInstance Win32_Process -Filter "Name='node.exe'" |
    Where-Object { $_.CommandLine -like '*ArmanOskouei.ir*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
  Write-Output '--- log tail ---'
  if (Test-Path -LiteralPath $log) { Get-Content -LiteralPath $log -Tail 12 }
  exit 124
}

Write-Output ("exited code=" + $p.ExitCode)
Write-Output '--- stdout tail ---'
if (Test-Path -LiteralPath $log) { Get-Content -LiteralPath $log -Tail 10 }
if ((Test-Path -LiteralPath $err) -and (Get-Item -LiteralPath $err).Length -gt 0) {
  Write-Output '--- stderr tail ---'
  Get-Content -LiteralPath $err -Tail 10
}
exit $p.ExitCode
