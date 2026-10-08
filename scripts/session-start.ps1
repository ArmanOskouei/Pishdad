<#
  session-start.ps1 — روتین شروع سشن (graphify + چک + تختهٔ تسک)

  چرا این اسکریپت وجود دارد
  ────────────────────────
  روی ویندوز، junctionِ `pishdad-core/backend/public/storage` را خودِ Laravel
  داخل کانتینر ساخته و targetش یک مسیر لینوکسی است (`/var/www/html/...`).
  ویندوز نمی‌تواند آن را بخواند ⇒ `WinError 1920` و کرشِ `detect_incremental`
  در graphify (پیش از هر استخراجی `stat` می‌کند). این اسکریپت junction را
  موقتاً بیرون می‌برد (move روی همان درایو ⇒ خودِ reparse point منتقل می‌شود،
  نه بازسازی)، `graphify update` می‌زند و در `finally` بازمی‌گرداند.

  اجرا
  ────
      powershell -ExecutionPolicy Bypass -File scripts/session-start.ps1
      powershell -File scripts/session-start.ps1 -NoLabel          # بدون نام‌گذاری community
      powershell -File scripts/session-start.ps1 -SkipGraphify     # فقط چک تسک‌ها

  خروجی فقط ASCII است تا در کنسول ویندوز به‌هم نریزد؛ متنِ فارسیِ تخته در
  `.local/session/board-*.md` (UTF-8) نوشته می‌شود و با ابزار read خوانده شود.

  @see docs/SESSION-START.md
  @see docs/tools/task-board.mjs
  @see docs/tools/tasks-guard.mjs
#>
[CmdletBinding()]
param(
  [switch]$NoLabel,
  [switch]$SkipGraphify
)

$ErrorActionPreference = "Continue"
$script:Fail = @()

function Say($msg) { Write-Host $msg }

$root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $root

$junction = Join-Path $root "pishdad-core\backend\public\storage"
$holdDir  = Join-Path (Split-Path -Parent $root) ".armanoskouei-session-tmp"
$holdItem = Join-Path $holdDir "storage"

Say "================================================================"
Say " SESSION START - $root"
Say " $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
Say "================================================================"

function Test-BrokenReparse($p) {
  if (-not (Test-Path -LiteralPath $p)) { return $false }
  $item = Get-Item -LiteralPath $p -Force -ErrorAction SilentlyContinue
  if (-not $item) { return $false }
  if (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return $false }
  # (۱) junctionِ سالم LinkType و Target دارد؛ این یکی هر دو را از دست داده
  #     چون targetش غیرقابل‌دسترس است.
  if (-not $item.LinkType -or -not $item.Target) { return $true }
  # (۲) در غیر این صورت، واقعاً تلاش کن درش را باز کنی.
  try { [System.IO.Directory]::GetFileSystemEntries($item.FullName) | Out-Null; return $false }
  catch { return $true }
}

# ── 0. بازیابیِ هر holdِ جامانده از اجرای نیمه‌تمام ───────────────────────────
if ((Test-Path -LiteralPath $holdItem) -and (-not (Test-Path -LiteralPath $junction))) {
  Say "[recover] found leftover hold, restoring junction"
  if (-not (Test-Path -LiteralPath $holdDir)) { New-Item -ItemType Directory -Force -Path $holdDir | Out-Null }
  cmd /c move "$holdItem" "$junction" | Out-Null
}

# ── 1. graphify ─────────────────────────────────────────────────────────────
$movedOut = $false
if ($SkipGraphify) {
  Say "[graphify] skipped (-SkipGraphify)"
} else {
  if (Test-BrokenReparse $junction) {
    Say "[junction] broken reparse point detected -> moving out for the scan"
    if (-not (Test-Path -LiteralPath $holdDir)) { New-Item -ItemType Directory -Force -Path $holdDir | Out-Null }
    cmd /c move "$junction" "$holdItem" | Out-Null
    if ($LASTEXITCODE -ne 0) { $script:Fail += "junction move-out failed (exit $LASTEXITCODE)"; Say "[junction] MOVE-OUT FAILED" }
    else { $movedOut = $true }
  } else {
    Say "[junction] ok or absent - nothing to move"
  }

  try {
    Say "[graphify] update (AST, code-only)..."
    $t0 = Get-Date
    python -m graphify update "$root" 2>&1 |
      Select-String -Pattern "Rebuilt:|AST extraction:|Rebuild failed|Error|error" |
      Select-Object -Last 4 |
      ForEach-Object { "   " + $_.Line.Trim() }
    Say ("   done in {0:n1}s" -f ((Get-Date) - $t0).TotalSeconds)
  } finally {
    if ($movedOut) {
      if (Test-Path -LiteralPath $junction) { Remove-Item -LiteralPath $junction -Force -Recurse -ErrorAction SilentlyContinue }
      cmd /c move "$holdItem" "$junction" | Out-Null
      if ($LASTEXITCODE -eq 0) {
        Say "[junction] restored"
        if ((Test-Path -LiteralPath $holdDir) -and -not (Get-ChildItem -LiteralPath $holdDir -Force -ErrorAction SilentlyContinue)) {
          Remove-Item -LiteralPath $holdDir -Force -ErrorAction SilentlyContinue
        }
      }
      else { $script:Fail += "junction restore failed (exit $LASTEXITCODE) - it is in $holdItem"; Say "[junction] RESTORE FAILED - hold kept at $holdItem" }
    }
  }
}

# ── 2. سلامتِ گراف ──────────────────────────────────────────────────────────
$graph = Join-Path $root "graphify-out\graph.json"
if (Test-Path -LiteralPath $graph) {
  $stats = node -e "try{const g=require(process.argv[1]);console.log((g.nodes||[]).length+' '+(g.links||[]).length)}catch(e){console.log('ERR')}" $graph 2>$null
  $mt = (Get-Item -LiteralPath $graph).LastWriteTime.ToString("yyyy-MM-dd HH:mm")
  Say "[graph] nodes/links = $stats   (mtime $mt)"
} else {
  $script:Fail += "graphify-out/graph.json missing"
  Say "[graph] MISSING"
}

# ── 3. نام‌گذاریِ communityهای تازه (best-effort) ────────────────────────────
if (-not $SkipGraphify -and -not $NoLabel) {
  Say "[graphify] label --missing-only (best-effort)..."
  python -m graphify label "$root" --missing-only 2>&1 |
    Select-String -Pattern "Done|communities|Error|error" |
    Select-Object -Last 2 |
    ForEach-Object { "   " + $_.Line.Trim() }
}

# ── 4. دفتر تسک‌ها: guard (+ خودترمیمیِ ایندکس) ─────────────────────────────
Say "[tasks] guard..."
$guard = node docs/tools/tasks-guard.mjs 2>&1
if ($LASTEXITCODE -ne 0) {
  Say "   guard FAIL -> regenerating index and retrying"
  node docs/tools/tasks-index.mjs | ForEach-Object { "   " + $_ }
  $guard = node docs/tools/tasks-guard.mjs 2>&1
}
if ($LASTEXITCODE -eq 0) {
  $summary = ($guard | Select-String -Pattern "task rows=").Line
  Say "   PASS $($summary -replace '\s+',' ')"
} else {
  $script:Fail += "tasks-guard FAIL"
  $guard | Where-Object { $_ -match "✗|FAIL" } | ForEach-Object { "   " + $_ }
}

# ── 5. تختهٔ تسک (خلاصهٔ همهٔ دسته‌ها) ────────────────────────────────────────
Say "[tasks] board summary..."
node docs/tools/task-board.mjs | ForEach-Object { "   " + $_ }

# ── 6. نتیجه ────────────────────────────────────────────────────────────────
Say "----------------------------------------------------------------"
if ($script:Fail.Count) {
  Say "SESSION START: DEGRADED"
  $script:Fail | ForEach-Object { Say "  !! $_" }
  exit 1
} else {
  Say "SESSION START: OK"
  Say "Next: ask the user which category board to show (admin | backend | frontend | other)."
}
