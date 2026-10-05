<#
.SYNOPSIS
  Moves opencode's XDG data off the C: drive onto E: by setting the XDG_* user
  environment variables, then (optionally) removing the now-orphaned C: folders.

.DESCRIPTION
  opencode stores its state under standard XDG locations, which on Windows default
  to the user profile on C:. To relocate them to another drive we set the four XDG
  environment variables for the current user, copy the existing data across, verify
  the copy, and only then delete the originals.

  IMPORTANT: close opencode completely before running this. opencode keeps live
  state open (kv.json, model.json, prompt-history.jsonl, snapshot/, tool-output/).
  Copying or deleting while it runs risks corrupting active sessions.

  Run it from a plain PowerShell window with opencode closed:

    powershell -ExecutionPolicy Bypass -File scripts\migrate-opencode-xdg.ps1 `
        -TargetRoot E:\opencode-data

  Add -DeleteSource (after a successful verification) to also remove the old C: folders.
  Default is copy + verify + set env vars, WITHOUT deleting, so you can confirm the
  new location works before reclaiming space.

.EXAMPLE
  # step 1 (safe): copy, verify, set env vars, keep originals
  .\scripts\migrate-opencode-xdg.ps1 -TargetRoot E:\opencode-data
  # step 2: launch opencode once from a new shell, confirm sessions/tools load
  # step 3 (cleanup): remove the now-unused C: folders
  .\scripts\migrate-opencode-xdg.ps1 -TargetRoot E:\opencode-data -DeleteSource
#>
[CmdletBinding()]
param(
    [string]$TargetRoot = 'E:\opencode-data',
    [switch]$DeleteSource
)

$ErrorActionPreference = 'Stop'

# --- Safety guard: opencode must not be running --------------------------------
$running = Get-Process -ErrorAction SilentlyContinue |
    Where-Object { $_.ProcessName -match 'opencode|bun' }
if ($running) {
    throw ("opencode appears to be running (PIDs: " +
        ($running.Id -join ', ') +
        "). Close opencode completely and re-run in a fresh PowerShell window. " +
        "Aborting to protect live session state.")
}

$xdg = [ordered]@{
    XDG_CONFIG_HOME = "$env:USERPROFILE\.config"
    XDG_DATA_HOME   = "$env:USERPROFILE\.local\share"
    XDG_CACHE_HOME  = "$env:USERPROFILE\.cache"
    XDG_STATE_HOME  = "$env:USERPROFILE\.local\state"
}

# Only the opencode subfolder of each XDG root is moved; other tools that share
# the same XDG roots (.config\git, .cache\pip, ...) are left untouched.
$moveMap = @(
    @{ Var = 'XDG_CONFIG_HOME'; Root = 'config'; Sub = 'opencode' }
    @{ Var = 'XDG_DATA_HOME';   Root = 'share';  Sub = 'opencode' }
    @{ Var = 'XDG_CACHE_HOME';  Root = 'cache';  Sub = 'opencode' }
    @{ Var = 'XDG_STATE_HOME';  Root = 'state';  Sub = 'opencode' }
)

function Get-DirStats([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return $null }
    $m = Get-ChildItem -LiteralPath $Path -Recurse -File -Force -ErrorAction SilentlyContinue |
        Measure-Object -Property Length -Sum
    return [pscustomobject]@{ Files = $m.Count; Bytes = [int64]$m.Sum }
}

New-Item -ItemType Directory -Force -Path $TargetRoot | Out-Null

# --- Phase 1: copy + verify ------------------------------------------------------
$plan = @()
foreach ($entry in $moveMap) {
    $src = Join-Path $xdg[$entry.Var] $entry.Sub
    $dst = Join-Path (Join-Path $TargetRoot $entry.Root) $entry.Sub
    $s = Get-DirStats $src
    if (-not $s) { Write-Host "SKIP  $($entry.Sub) (not present at $src)"; continue }
    $plan += [pscustomobject]@{ Sub = $entry.Sub; Var = $entry.Var; Root = $entry.Root; Src = $src; Dst = $dst; SrcFiles = $s.Files; SrcBytes = $s.Bytes }
}

if (-not $plan) { throw "Nothing to migrate - no opencode XDG folders found." }

Write-Host "`n=== PHASE 1: copy -> $TargetRoot ==="
foreach ($p in $plan) {
    Write-Host ("COPY  {0}  ({1} files, {2:N1} MB)" -f $p.Sub, $p.SrcFiles, ($p.SrcBytes / 1MB))
    if (Test-Path -LiteralPath $p.Dst) { Remove-Item -LiteralPath $p.Dst -Recurse -Force }
    robocopy $p.Src $p.Dst /E /R:1 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -gt 7) { throw "robocopy failed for $($p.Sub) (exit $LASTEXITCODE)" }
}

Write-Host "`n=== PHASE 1b: verify copy integrity ==="
$verified = $true
foreach ($p in $plan) {
    $d = Get-DirStats $p.Dst
    $ok = ($d.Files -eq $p.SrcFiles) -and ($d.Bytes -eq $p.SrcBytes)
    if (-not $ok) { $verified = $false }
    Write-Host ("{0}  {1}: src {2} files/{3} MB  ->  dst {4} files/{5} MB" -f
        $(if ($ok) { 'PASS ' } else { 'FAIL ' }), $p.Sub, $p.SrcFiles, [math]::Round($p.SrcBytes/1MB,1), $d.Files, [math]::Round($d.Bytes/1MB,1))
}
if (-not $verified) { throw "Copy verification failed - NOT setting env vars, NOT deleting. Aborting." }

# --- Phase 2: point the XDG vars at the new location -----------------------------
# Phase 1 copied each tool folder to <TargetRoot>\<rootName>\opencode, so each
# XDG var must point at <TargetRoot>\<rootName> (the PARENT of the tool folder).
Write-Host "`n=== PHASE 2: set user environment variables ==="
$setMap = [ordered]@{
    XDG_CONFIG_HOME = (Join-Path $TargetRoot 'config')
    XDG_DATA_HOME   = (Join-Path $TargetRoot 'share')
    XDG_CACHE_HOME  = (Join-Path $TargetRoot 'cache')
    XDG_STATE_HOME  = (Join-Path $TargetRoot 'state')
}

foreach ($k in $setMap.Keys) {
    $v = $setMap[$k]
    New-Item -ItemType Directory -Force -Path $v | Out-Null
    [Environment]::SetEnvironmentVariable($k, $v, 'User')
    Write-Host "SET   $k = $v  (User)   -> opencode will read $v\opencode"
}
Write-Host "`nEnvironment variables apply to NEW shells only. Close and reopen opencode to pick them up."

# --- Phase 3 (optional): remove the old C: folders -------------------------------
if ($DeleteSource) {
    Write-Host "`n=== PHASE 3: delete old C: folders ==="
    foreach ($p in $plan) {
        $s = Get-DirStats $p.Src
        Write-Host ("DEL   {0}  ({1} files, {2:N1} MB)  {3}" -f $p.Src, $s.Files, ($s.Bytes/1MB), $p.Sub)
        Remove-Item -LiteralPath $p.Src -Recurse -Force
    }
    Write-Host "`nDone. C: reclaimed. Confirm opencode loads your sessions before discarding anything else."
} else {
    Write-Host "`nSKIPPED deletion (default). Verify opencode works from the new location, then re-run with -DeleteSource."
}
