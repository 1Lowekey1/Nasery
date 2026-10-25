<#
.SYNOPSIS
  Start NaSeRy's local PostgreSQL (C:\xampp\pgsql\data). Safe to run twice.
  powershell -ExecutionPolicy Bypass -File backend\database\local\start-local-db.ps1
#>
param(
  [string]$PgBin = 'C:\Program Files\PostgreSQL\18\bin',
  [string]$DataDir = 'C:\xampp\pgsql\data'
)
$pgctl = Join-Path $PgBin 'pg_ctl.exe'
& $pgctl -D $DataDir status *> $null
if ($LASTEXITCODE -eq 0) { Write-Host 'PostgreSQL is already running.'; exit 0 }

# Run pg_ctl with its own output files: the server it launches then holds no handle
# to this window or to a caller's pipeline (which would otherwise never "finish").
# Server log: <DataDir>\log\ (logging_collector). Start-up messages: <DataDir>\startup.log.
$out = Join-Path $DataDir 'pg_ctl.out'; $err = Join-Path $DataDir 'pg_ctl.err'
# (Not -Wait: in Windows PowerShell that also waits for the server it starts, i.e. forever.)
$p = Start-Process -FilePath $pgctl -NoNewWindow -PassThru `
  -ArgumentList @('-D', "`"$DataDir`"", '-l', "`"$(Join-Path $DataDir 'startup.log')`"", '-w', '-t', '60', 'start') `
  -RedirectStandardOutput $out -RedirectStandardError $err
$null = $p.Handle   # keep the handle, or ExitCode reads empty after exit (Windows PowerShell quirk)
$p.WaitForExit()
if ($p.ExitCode -ne 0) {
  Get-Content $out, $err -ErrorAction SilentlyContinue | Write-Host
  Write-Host "Could not start PostgreSQL. See $DataDir\startup.log and $DataDir\log\" -ForegroundColor Red
  exit 1
}
Write-Host 'PostgreSQL is running.' -ForegroundColor Green
exit 0
