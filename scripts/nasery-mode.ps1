<#
.SYNOPSIS
  Switch NaSeRy on this PC between PRODUCTION (public site through Cloudflare)
  and DEVELOPMENT (npm run dev + debug pages).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\nasery-mode.ps1 production -PublicUrl https://nasery.com
  powershell -ExecutionPolicy Bypass -File scripts\nasery-mode.ps1 development

.DESCRIPTION
  production
    - backend\.env: APP_ENV=production, APP_DEBUG=false, the public address
      (APP_URL, FRONTEND_APP_URL, FRONTEND_URLS, SANCTUM_STATEFUL_DOMAINS),
      secure host-only session cookie, trusted local proxy (cloudflared/Apache),
      file cache, info-level logs.
    - Laravel caches (config, routes, events, views) + optimized autoloader.
    - Builds the website (frontend\.output\public) to call /api on its own
      address, which Apache serves on 127.0.0.1:8080 (server\apache-local.conf).
    - Tells the queue worker to restart so it picks up the new settings.
  development
    - Puts those .env settings back to the local-development values, clears
      the Laravel caches. Use `npm run dev` (frontend) as before.

  Only the settings listed below are changed; passwords and keys in .env are
  never read out or printed.
#>
param(
  [Parameter(Mandatory = $true, Position = 0)][ValidateSet('production', 'development')][string]$Mode,
  [string]$PublicUrl = '',
  # Force rebuilding PHP's optimized class index (normally only after composer install/update).
  [switch]$RebuildAutoload,
  [switch]$SkipFrontend,
  [string]$Php = 'C:\xampp\php8.5.10\php.exe',
  [string]$Composer = 'C:\composer\composer.phar'
)

$ErrorActionPreference = 'Continue'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Backend = Join-Path $Root 'backend'
$Frontend = Join-Path $Root 'frontend'
$EnvFile = Join-Path $Backend '.env'

function Step($t) { Write-Host "`n== $t" -ForegroundColor Cyan }
function Fail($t) { Write-Host "`nSTOPPED: $t" -ForegroundColor Red; exit 1 }
function Run([string]$What, [scriptblock]$Cmd) {
  & $Cmd
  if ($LASTEXITCODE -ne 0) { Fail "$What failed (exit $LASTEXITCODE)." }
}

if (-not (Test-Path $EnvFile)) { Fail 'backend\.env not found.' }

if ($Mode -eq 'production') {
  if ($PublicUrl -notmatch '^https://[a-z0-9.-]+$') { Fail 'Give the public address, e.g. -PublicUrl https://nasery.com (https, no path, no trailing slash).' }
  $hostName = ([Uri]$PublicUrl).Host
  $values = [ordered]@{
    APP_ENV = 'production'; APP_DEBUG = 'false'; LOG_LEVEL = 'info'
    APP_URL = $PublicUrl; FRONTEND_APP_URL = $PublicUrl; FRONTEND_URLS = $PublicUrl
    SANCTUM_STATEFUL_DOMAINS = $hostName
    SESSION_DOMAIN = 'null'; SESSION_SECURE_COOKIE = 'true'
    # cloudflared and Apache's /api proxy both connect from this PC.
    TRUSTED_PROXIES = '127.0.0.1,::1'
    DB_PERSISTENT = 'true'; CACHE_STORE = 'file'
  }
} else {
  $values = [ordered]@{
    APP_ENV = 'local'; APP_DEBUG = 'true'; LOG_LEVEL = 'debug'
    APP_URL = 'http://localhost:8000'; FRONTEND_APP_URL = 'http://localhost:3000'; FRONTEND_URLS = 'http://localhost:3000'
    SANCTUM_STATEFUL_DOMAINS = 'localhost:3000,127.0.0.1:3000'
    SESSION_DOMAIN = 'localhost'; SESSION_SECURE_COOKIE = 'false'
    TRUSTED_PROXIES = ''
    DB_PERSISTENT = 'false'; CACHE_STORE = 'database'
  }
}

Step "Updating backend\.env for $Mode"
$lines = [Collections.Generic.List[string]](Get-Content $EnvFile -Encoding UTF8)
foreach ($k in $values.Keys) {
  $i = -1
  for ($n = 0; $n -lt $lines.Count; $n++) { if ($lines[$n] -match "^\s*$k\s*=") { $i = $n; break } }
  $line = "$k=$($values[$k])"
  if ($i -ge 0) { $lines[$i] = $line } else { $lines.Add($line) }
  Write-Host "  $line"
}
[IO.File]::WriteAllLines($EnvFile, $lines, (New-Object Text.UTF8Encoding($false)))

Push-Location $Backend
try {
  if ($Mode -eq 'production') {
    Step 'Optimizing the backend'
    # The optimized class index (~11 s to rebuild: it scans all of vendor\) only goes stale
    # when dependencies change; new classes in app\ still load without it. Rebuild it only
    # when composer.json / composer.lock is newer than the index, or with -RebuildAutoload.
    $classmap = Join-Path $Backend 'vendor\composer\autoload_classmap.php'
    $deps = @('composer.json', 'composer.lock') | ForEach-Object { Join-Path $Backend $_ } | Where-Object { Test-Path $_ }
    $stale = $RebuildAutoload -or -not (Test-Path $classmap) -or
      [bool]($deps | Where-Object { (Get-Item $_).LastWriteTime -gt (Get-Item $classmap).LastWriteTime })
    if ($stale) { Run 'composer dump-autoload' { & $Php $Composer dump-autoload --optimize --no-interaction --quiet } }
    else { Write-Host '  Class index is up to date (dependencies unchanged).' }
    Run 'artisan optimize' { & $Php artisan optimize }
  } else {
    Step 'Clearing the backend caches'
    Run 'artisan optimize:clear' { & $Php artisan optimize:clear }
  }
  & $Php artisan queue:restart | Out-Null
  Write-Host '  Queue worker told to restart (it stops after its current job; start it again if it does not come back on its own).'
} finally { Pop-Location }

if ($Mode -eq 'production' -and -not $SkipFrontend) {
  Step 'Building the website (frontend\.output\public)'
  # Relative addresses: the site and /api share one origin, so the build
  # works on any domain (temporary tunnel link or the real one) unchanged.
  $env:NUXT_PUBLIC_API_BASE = '/api/v1'
  $env:NUXT_PUBLIC_API_BASE_URL = '/api/v1'
  $env:NUXT_PUBLIC_BACKEND_URL = '/'
  $env:NUXT_PUBLIC_APP_BASE_URL = $PublicUrl
  Push-Location $Frontend
  try { Run 'npm run generate' { npm run generate } } finally { Pop-Location }
  if (-not (Test-Path (Join-Path $Frontend '.output\public\200.html'))) { Fail 'The build did not produce .output\public\200.html.' }
}

Step 'Health check'
Push-Location $Backend
try { & $Php artisan nasery:doctor | Select-Object -Last 3 } finally { Pop-Location }

if ($Mode -eq 'production') {
  Write-Host "`nProduction mode is set for $PublicUrl" -ForegroundColor Green
  Write-Host 'The site is served by Apache on http://127.0.0.1:8080 (the Cloudflare tunnel points there).'
  Write-Host 'Changed the frontend? Run this again to rebuild it.'
} else {
  Write-Host "`nDevelopment mode is set." -ForegroundColor Green
  Write-Host 'Run the website with: cd frontend; npm run dev   (http://localhost:3000)'
}
