<#
.SYNOPSIS
  Stop NaSeRy's servers. Called by stop.bat. "-All" (stop.bat all) also stops the database.
#>
param([switch]$All, [Parameter(ValueFromRemainingArguments = $true)]$Rest)
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ($Rest -contains 'all') { $All = $true }

function Ok($t) { Write-Host "  [ok] $t" -ForegroundColor Green }

# Queue worker and scheduler: let the worker finish its current e-mail first.
$php = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
  Where-Object { $_.CommandLine -like '*artisan queue:work*' -or $_.CommandLine -like '*artisan schedule:work*' }
if ($php) { $php | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }; Ok 'Queue worker and scheduler stopped' }

# Apache (this project's config only; XAMPP's own Apache is left alone).
$httpd = Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" -ErrorAction SilentlyContinue |
  Where-Object { $_.CommandLine -like '*apache-local.conf*' }
if ($httpd) { $httpd | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }; Ok 'Apache stopped' }
# Apache's worker child has no config on its command line: anything left on 8000/8080.
foreach ($port in 8000, 8080, 3000) {
  Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique |
    ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue; Ok "Stopped what was running on port $port" }
}

if (Get-Process cloudflared -ErrorAction SilentlyContinue) { Stop-Process -Name cloudflared -Force; Ok 'Cloudflare tunnel stopped (the link no longer works)' }

if ($All) {
  & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Root 'backend\database\local\stop-local-db.ps1')
}
Write-Host '  Done. The "NaSeRy - ..." windows can be closed.'
