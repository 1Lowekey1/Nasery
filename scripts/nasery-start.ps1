<#
.SYNOPSIS
  Start everything NaSeRy needs on this PC. Called by start.bat (double-click that).

.DESCRIPTION
  public  (default)  the real site for everyone, through a Cloudflare tunnel:
            database -> Apache (API :8000 + website :8080) -> tunnel -> reads the
            tunnel's https link -> production settings for that link (rebuilds the
            website only when frontend files changed) -> queue worker + scheduler
            -> checks the link answers -> copies it to the clipboard.
  dev                this PC only: database -> Apache (API) -> development settings
            -> queue worker + scheduler -> `npm run dev` on http://localhost:3000.

  Each server runs in its own window ("NaSeRy - ..."); leave them open.
  Things already running are left alone, so starting twice is safe.

  Permanent address (domain + named tunnel): put the address in
  scripts\public-url.txt (e.g. https://nasery.bid). The tunnel itself is the
  "Cloudflared" Windows service (installed once with "cloudflared service
  install <token>"), or, without the service, the tunnel's name in
  scripts\tunnel-name.txt. Then the link never changes.
#>
param(
  [ValidateSet('public', 'dev')][string]$Mode = 'public',
  [switch]$Rebuild,
  [string]$Php = 'C:\xampp\php8.5.10\php.exe',
  [string]$Httpd = 'C:\xampp\apache\bin\httpd.exe'
)

$ErrorActionPreference = 'Continue'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Backend = Join-Path $Root 'backend'
$Frontend = Join-Path $Root 'frontend'
$Logs = Join-Path $Backend 'storage\logs'
$TunnelLog = Join-Path $Logs 'cloudflared.log'

function Ok($t) { Write-Host "  [ok] $t" -ForegroundColor Green }
function Info($t) { Write-Host "  ...  $t" }
function Fail($t) { Write-Host "`n  [!] $t" -ForegroundColor Red; Read-Host '  Press Enter to close'; exit 1 }
function Listening($port) { [bool](Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) }
function ArtisanRunning($what) {
  @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like "*artisan $what*" })
}
# A titled cmd window that stays open (so errors stay readable).
function Window($title, $dir, $cmd) {
  Start-Process cmd.exe -WorkingDirectory $dir -ArgumentList '/k', "title NaSeRy - $title (keep this open) & $cmd" | Out-Null
}
function Workers([bool]$changed) {
  if (-not $changed -and (ArtisanRunning 'queue:work') -and (ArtisanRunning 'schedule:work')) { Ok 'Queue worker and scheduler already running'; return }
  # A worker started under other settings keeps them until restarted: replace it.
  foreach ($w in 'queue:work', 'schedule:work') {
    ArtisanRunning $w | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
  }
  # Loops: after `queue:restart` (nasery-mode.ps1 sends it) the worker exits and comes straight back with the new settings.
  Window 'Queue (e-mails)' $Backend "for /L %i in (0,0,1) do `"$Php`" artisan queue:work --tries=3 --timeout=120"
  Window 'Scheduler' $Backend "`"$Php`" artisan schedule:work"
  Ok 'Queue worker (e-mails) and scheduler (ticket holds, reminders, billing) started'
}

function DemoEvent {
  # The always-open demo event (ticket purchase -> check-in): create it or move its
  # door window now, instead of waiting for the scheduler's next hourly run.
  # Safe to repeat; never touches tickets. Does nothing without the demo dataset.
  Push-Location $Backend
  try { & $Php artisan nasery:demo:event *> $null } finally { Pop-Location }
  if ($LASTEXITCODE -eq 0) { Ok 'Demo event ready (check-in open)' } else { Info 'Demo event not updated (run: php artisan nasery:demo:event)' }
}

Write-Host "`n  NaSeRy ($Mode)`n  ------"

# 1. Database ----------------------------------------------------------------
if (Listening 5433) { Ok 'Database already running (127.0.0.1:5433)' }
else {
  # In its own hidden window, NOT piped: PostgreSQL's server process inherits the
  # caller's output handles, so capturing them (| Out-Null) would wait forever.
  Start-Process powershell -WindowStyle Hidden -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
    "`"$(Join-Path $Backend 'database\local\start-local-db.ps1')`"" | Out-Null
  Info 'Starting the database'
  for ($i = 0; $i -lt 60 -and -not (Listening 5433); $i++) { Start-Sleep 1 }
  if (-not (Listening 5433)) { Fail 'The database did not start. See C:\xampp\pgsql\data\startup.log' }
  Ok 'Database started (127.0.0.1:5433)'
}

# 2. Apache (API on :8000, website on :8080) ---------------------------------
if (Listening 8000) { Ok 'Apache already running' }
else {
  Window 'Apache' $Backend "`"$Httpd`" -f `"$Backend\server\apache-local.conf`""
  for ($i = 0; $i -lt 30 -and -not (Listening 8000); $i++) { Start-Sleep 1 }
  if (-not (Listening 8000)) { Fail 'Apache did not start. Look at its window or storage\logs\apache-error.log' }
  Ok 'Apache started (API http://localhost:8000)'
}

$envFile = Join-Path $Backend '.env'
$current = (Select-String -Path $envFile -Pattern '^APP_ENV=(.*)$' | Select-Object -First 1).Matches.Groups[1].Value.Trim()
$currentUrl = (Select-String -Path $envFile -Pattern '^APP_URL=(.*)$' | Select-Object -First 1).Matches.Groups[1].Value.Trim()
$modeScript = Join-Path $PSScriptRoot 'nasery-mode.ps1'

# ---------------------------------------------------------------- dev ---------
if ($Mode -eq 'dev') {
  $changed = $current -ne 'local'
  if ($changed) {
    Info 'Switching to development settings'
    & powershell -NoProfile -ExecutionPolicy Bypass -File $modeScript development | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail 'Switching to development failed. Run scripts\nasery-mode.ps1 development to see why.' }
  }
  Ok 'Development settings'
  Workers $changed
  DemoEvent
  if (Listening 3000) { Ok 'Website already running' } else { Window 'Website (dev)' $Frontend 'npm.cmd run dev'; Info 'Website starting (about 20 seconds)' }
  for ($i = 0; $i -lt 120 -and -not (Listening 3000); $i++) { Start-Sleep 1 }
  Start-Process 'http://localhost:3000'
  Ok 'Opened http://localhost:3000'
  Start-Sleep 5
  exit 0
}

# ---------------------------------------------------------------- public ------
# 3. Tunnel -------------------------------------------------------------------
$cf = @("${env:ProgramFiles(x86)}\cloudflared\cloudflared.exe", "$env:ProgramFiles\cloudflared\cloudflared.exe",
  "$env:LOCALAPPDATA\Microsoft\WinGet\Links\cloudflared.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $cf) { Fail 'cloudflared was not found. Install it once: winget install --id Cloudflare.cloudflared' }

$urlFile = Join-Path $PSScriptRoot 'public-url.txt'
$nameFile = Join-Path $PSScriptRoot 'tunnel-name.txt'
$tunnelUp = [bool](Get-Process cloudflared -ErrorAction SilentlyContinue)

$cfService = Get-Service Cloudflared -ErrorAction SilentlyContinue
if ((Test-Path $urlFile) -and $cfService) {
  # Named tunnel installed as the "Cloudflared" Windows service (cloudflared service install <token>):
  # it starts with Windows and reconnects by itself; the address is fixed (public-url.txt).
  $url = (Get-Content $urlFile -TotalCount 1).Trim().TrimEnd('/')
  if ($cfService.Status -ne 'Running') {
    try { Start-Service Cloudflared -ErrorAction Stop } catch { Fail 'The Cloudflared service is stopped and could not be started. Start it in Services (services.msc), or run start.bat as administrator.' }
  }
  Ok "Cloudflare tunnel ($url)"
} elseif ((Test-Path $urlFile) -and (Test-Path $nameFile)) {
  # Named tunnel run by name (needs this PC's cloudflared login): fixed address.
  $url = (Get-Content $urlFile -TotalCount 1).Trim().TrimEnd('/')
  if (-not $tunnelUp) { Window 'Cloudflare tunnel' $Root "`"$cf`" tunnel --no-autoupdate run $((Get-Content $nameFile -TotalCount 1).Trim())" }
  Ok "Cloudflare tunnel ($url)"
} else {
  # Quick tunnel: a new random https://....trycloudflare.com link each start.
  $url = $null
  if ($tunnelUp -and (Test-Path $TunnelLog)) {
    $url = (Select-String -Path $TunnelLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -Last 1).Matches.Value
  }
  if ($url) { Ok "Cloudflare tunnel already running" }
  else {
    Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force   # one without a readable link
    Remove-Item $TunnelLog -ErrorAction SilentlyContinue
    Window 'Cloudflare tunnel' $Root "`"$cf`" tunnel --no-autoupdate --url http://127.0.0.1:8080 --logfile `"$TunnelLog`""
    Info 'Waiting for the Cloudflare link'
    for ($i = 0; $i -lt 60 -and -not $url; $i++) {
      Start-Sleep 1
      if (Test-Path $TunnelLog) { $url = (Select-String -Path $TunnelLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -Last 1).Matches.Value }
    }
    if (-not $url) { Fail 'No link from Cloudflare within a minute. Check the "Cloudflare tunnel" window (internet down?).' }
    Ok 'Cloudflare tunnel started'
  }
}

# 4. Production settings for this link ---------------------------------------
# Rebuild the website only if a frontend source file is newer than the last build.
$built = Join-Path $Frontend '.output\public\200.html'
$stale = $Rebuild -or -not (Test-Path $built)
if (-not $stale) {
  $since = (Get-Item $built).LastWriteTime
  $sources = @()
  foreach ($d in 'app', 'public', 'shared') { $p = Join-Path $Frontend $d; if (Test-Path $p) { $sources += Get-ChildItem $p -Recurse -File } }
  foreach ($f in 'nuxt.config.ts', 'package.json', 'app.config.ts') { $p = Join-Path $Frontend $f; if (Test-Path $p) { $sources += Get-Item $p } }
  $stale = [bool]($sources | Where-Object { $_.LastWriteTime -gt $since } | Select-Object -First 1)
}
$changed = -not ($current -eq 'production' -and $currentUrl -eq $url -and -not $stale)
if (-not $changed) {
  Ok 'Production settings already set for this link'
} else {
  $args2 = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $modeScript, 'production', '-PublicUrl', $url)
  if (-not $stale) { $args2 += '-SkipFrontend' } else { Info 'Frontend changed: rebuilding the website (1-2 minutes)' }
  Info 'Applying production settings'
  & powershell @args2 *> (Join-Path $Logs 'nasery-mode.log')
  if ($LASTEXITCODE -ne 0) { Fail "Production setup failed. Details: $Logs\nasery-mode.log" }
  Ok ('Production settings for the link' + $(if ($stale) { ' (website rebuilt)' } else { '' }))
}

# 5. Workers -----------------------------------------------------------------
Workers $changed
DemoEvent

# 6. Check the link from outside ---------------------------------------------
Info 'Checking the link answers (new links can take ~30 seconds to work)'
$okLink = $false
for ($i = 0; $i -lt 20 -and -not $okLink; $i++) {
  try { $okLink = (Invoke-WebRequest "$url/up" -UseBasicParsing -TimeoutSec 10).StatusCode -eq 200 } catch { Start-Sleep 3 }
}
Set-Clipboard -Value $url -ErrorAction SilentlyContinue
Write-Host ''
Write-Host '  =====================================================' -ForegroundColor Cyan
Write-Host "   $url" -ForegroundColor Cyan
Write-Host '  =====================================================' -ForegroundColor Cyan
if ($okLink) { Ok 'The link works. It is copied: paste it to share.' }
else { Write-Host '  [!] The link did not answer yet. Try it in a minute; if not, run stop.bat then start.bat.' -ForegroundColor Yellow }
if (-not (Test-Path $urlFile)) { Write-Host '  This link changes every time the tunnel starts.' }
Start-Process $url
Read-Host "`n  Press Enter to close this window (the site keeps running)"
