<#
.SYNOPSIS
  Runs a command with TEMP/TMP redirected into this repository's .local/tmp.

.DESCRIPTION
  By default Node/Next/npm and the PHP test suite use the system temp folder
  (C:\Users\<you>\AppData\Local\Temp on Windows). This wrapper scopes TEMP/TMP
  to <repo>/.local/tmp for the single command you pass, so builds and tests
  never write scratch files to the system drive. No global Windows setting is
  changed, so other applications are unaffected.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\with-project-temp.ps1 npm run build
  powershell -ExecutionPolicy Bypass -File scripts\with-project-temp.ps1 npm test
#>
# NOTE: intentionally no [CmdletBinding()]/param() block. That keeps PowerShell
# from parsing child flags (e.g. `-e`, `--offline`) as script parameters; they all
# land verbatim in $args.
$ErrorActionPreference = 'Stop'

$Command = $args

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$localTmp = Join-Path $repoRoot '.local\tmp'
New-Item -ItemType Directory -Force -Path $localTmp | Out-Null

$env:TEMP = $localTmp
$env:TMP = $localTmp
$env:TMPDIR = $localTmp
$env:npm_config_cache = (Join-Path $repoRoot '.local\cache\npm')

Write-Host "[with-project-temp] TEMP/TMP -> $localTmp"
Write-Host "[with-project-temp] npm cache -> $($env:npm_config_cache)"

if (-not $Command) {
    throw 'No command supplied. Usage: with-project-temp.ps1 <command> [args...]'
}

if ($Command.Count -eq 1) {
    & $Command[0]
} else {
    & $Command[0] $Command[1..($Command.Count - 1)]
}
exit $LASTEXITCODE
