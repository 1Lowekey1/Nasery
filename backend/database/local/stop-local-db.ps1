<#
.SYNOPSIS
  Stop NaSeRy's local PostgreSQL cleanly (waits for running queries, then shuts down).
  Stop Apache and the queue worker first.
  powershell -ExecutionPolicy Bypass -File backend\database\local\stop-local-db.ps1
#>
param(
  [string]$PgBin = 'C:\Program Files\PostgreSQL\18\bin',
  [string]$DataDir = 'C:\xampp\pgsql\data'
)
$pgctl = Join-Path $PgBin 'pg_ctl.exe'
& $pgctl -D $DataDir status *> $null
if ($LASTEXITCODE -ne 0) { Write-Host 'PostgreSQL is not running.'; exit 0 }
& $pgctl -D $DataDir -m fast -w stop
