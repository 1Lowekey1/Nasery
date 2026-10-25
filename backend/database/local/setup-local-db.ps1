<#
.SYNOPSIS
  One-time setup of NaSeRy's self-hosted PostgreSQL (replaces Supabase).

.DESCRIPTION
  Creates a private PostgreSQL 18 cluster under C:\xampp\pgsql\data that only
  this computer can reach (localhost, port 5433), builds the NaSeRy schema from
  database/supabase (0001 …), creates Laravel's tables, optionally loads data
  from a backup (.dump made by `php artisan nasery:db:backup`), points
  backend\.env at it, and runs `php artisan nasery:doctor`.

  YOU type both passwords when asked. They go straight into PostgreSQL and
  backend\.env and are never printed or written anywhere else. Your current
  backend\.env is saved first as backend\.env.supabase (git-ignored), so you
  can switch back any time.

  Run it in a normal PowerShell window (not as Administrator), from anywhere:
    powershell -ExecutionPolicy Bypass -File backend\database\local\setup-local-db.ps1 -FromBackup backend\storage\backups\<file>.dump

.PARAMETER FromBackup
  Optional. A .dump file from `nasery:db:backup` to copy all data from
  (users, organizations, events, tickets, …). Without it the database starts
  with reference data only (then run `php artisan nasery:demo:seed`).
#>
[CmdletBinding()]
param(
  [string]$FromBackup = '',
  [string]$PgBin = 'C:\Program Files\PostgreSQL\18\bin',
  [string]$DataDir = 'C:\xampp\pgsql\data',
  [int]$Port = 5433,
  [string]$Php = 'C:\xampp\php8.5.10\php.exe',
  [string]$SuperUser = 'nasery_super',
  [string]$Database = 'nasery',
  # For automated testing only: normally leave these out and type the passwords when asked.
  [Security.SecureString]$SuperPassword,
  [Security.SecureString]$AppPassword,
  [string]$EnvFile = ''
)

$ErrorActionPreference = 'Stop'
# Quiet PostgreSQL's informational notices ("already exists, skipping").
$env:PGOPTIONS = '-c client_min_messages=warning'
$Backend = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$SqlDir = Join-Path $Backend 'database\supabase'
if (-not $EnvFile) { $EnvFile = Join-Path $Backend '.env' }

function Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }
function Fail($text) { Write-Host "`nSTOPPED: $text" -ForegroundColor Red; exit 1 }
function Plain([Security.SecureString]$s) {
  $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s)
  try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }
}
function Ask-Password($label) {
  while ($true) {
    $a = Plain (Read-Host "$label (min. 12 characters)" -AsSecureString)
    $b = Plain (Read-Host "Type it again" -AsSecureString)
    if ($a -ne $b) { Write-Host 'They do not match, try again.' -ForegroundColor Yellow; continue }
    if ($a.Length -lt 12) { Write-Host 'Too short, use at least 12 characters.' -ForegroundColor Yellow; continue }
    if ($a -match "['`"\\\s]") { Write-Host "Please avoid quotes, backslashes and spaces." -ForegroundColor Yellow; continue }
    return $a
  }
}
function Psql([string]$User, [string]$Db, [string[]]$More) {
  # Native tools write notices to stderr; success is judged by the exit code alone.
  $ErrorActionPreference = 'Continue'
  & (Join-Path $PgBin 'psql.exe') -h 127.0.0.1 -p $Port -U $User -d $Db -q -v ON_ERROR_STOP=1 @More
  if ($LASTEXITCODE -ne 0) { Fail "psql failed ($User on $Db): $($More -join ' ')" }
}

# ---------------------------------------------------------------- checks
Step 'Checking prerequisites'
foreach ($exe in 'initdb.exe', 'pg_ctl.exe', 'psql.exe', 'pg_restore.exe') {
  if (-not (Test-Path (Join-Path $PgBin $exe))) { Fail "$exe not found in $PgBin (install PostgreSQL 18, or pass -PgBin)." }
}
if (-not (Test-Path $Php)) { Fail "PHP 8.5.10 not found at $Php" }
if (-not (Test-Path $EnvFile)) { Fail "backend\.env not found. Get it from Nathan first." }
if (Test-Path (Join-Path $DataDir 'PG_VERSION')) { Fail "$DataDir already has a database. Nothing was changed. (Delete that folder only if you really want to start over.)" }
if ($FromBackup) {
  $FromBackup = (Resolve-Path $FromBackup).Path
  Write-Host "Data will be copied from: $FromBackup"
}
$inUse = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
if ($inUse) { Fail "Port $Port is already in use. Pass another one with -Port." }

# ---------------------------------------------------------------- passwords
Step 'Passwords (typed by you, never shown)'
Write-Host "1) '$SuperUser' = the database administrator. Only for maintenance; keep it somewhere safe (password manager)."
$superPw = if ($SuperPassword) { Plain $SuperPassword } else { Ask-Password "Password for $SuperUser" }
Write-Host "2) 'postgres' = the login NaSeRy's backend uses (saved into backend\.env as DB_PASSWORD)."
$appPw = if ($AppPassword) { Plain $AppPassword } else { Ask-Password 'Password for the NaSeRy backend login' }
if ($appPw -eq $superPw) { Fail 'Use two different passwords.' }

# ---------------------------------------------------------------- cluster
# From here on native tools (initdb, psql, pg_restore, php) run; they print notices to
# stderr, so success is judged by each exit code. File steps stop on error explicitly.
$ErrorActionPreference = 'Continue'
Step "Creating the database cluster in $DataDir"
New-Item -ItemType Directory -Force -Path (Split-Path $DataDir) -ErrorAction Stop | Out-Null
$pwFile = Join-Path $env:TEMP ("nasery-pw-" + [guid]::NewGuid() + '.txt')
try {
  [IO.File]::WriteAllText($pwFile, $superPw)
  & (Join-Path $PgBin 'initdb.exe') -D $DataDir -U $SuperUser --pwfile=$pwFile --auth=scram-sha-256 -E UTF8 --locale-provider=icu --icu-locale=en-US --locale=C | Out-Host
  if ($LASTEXITCODE -ne 0) { Fail 'initdb failed.' }
} finally { Remove-Item $pwFile -Force -ErrorAction SilentlyContinue }

# Only this computer may connect, always with a password.
$conf = Join-Path $DataDir 'postgresql.conf'
Add-Content -Path $conf -Encoding ascii -ErrorAction Stop -Value @"

# ---- NaSeRy (setup-local-db.ps1) ----
listen_addresses = 'localhost'
port = $Port
password_encryption = scram-sha-256
max_connections = 100
shared_buffers = 256MB
timezone = 'UTC'
log_timezone = 'UTC'
logging_collector = on
log_directory = 'log'
log_min_duration_statement = 1000   # log queries slower than 1 s
"@
Set-Content -Path (Join-Path $DataDir 'pg_hba.conf') -Encoding ascii -ErrorAction Stop -Value @"
# NaSeRy: local connections only, password required.
# TYPE  DATABASE  USER  ADDRESS        METHOD
host    all       all   127.0.0.1/32   scram-sha-256
host    all       all   ::1/128        scram-sha-256
"@

Step "Starting PostgreSQL on localhost:$Port"
& (Join-Path $PSScriptRoot 'start-local-db.ps1') -PgBin $PgBin -DataDir $DataDir
if ($LASTEXITCODE -ne 0) { Fail "PostgreSQL did not start (see $DataDir\startup.log and $DataDir\log)." }

# ---------------------------------------------------------------- schema
$env:PGPASSWORD = $superPw
Step "Creating database '$Database' and the Supabase compatibility layer"
Psql $SuperUser 'postgres' @('-c', "CREATE DATABASE $Database")
Psql $SuperUser $Database @('-f', (Join-Path $PSScriptRoot '00_supabase_compat.sql'))
Psql $SuperUser $Database @('-c', "ALTER ROLE postgres PASSWORD '$appPw'")

$env:PGPASSWORD = $appPw
Step 'Building the NaSeRy schema (database\supabase)'
$files = Get-ChildItem $SqlDir -Filter '*.sql' | Where-Object { $_.Name -notlike '*.down.sql' } | Sort-Object Name
foreach ($f in $files) {
  Write-Host "  $($f.Name)"
  Psql 'postgres' $Database @('-f', $f.FullName)
}

# ---------------------------------------------------------------- .env
Step 'Pointing backend\.env at the local database'
$saved = Join-Path (Split-Path $EnvFile) '.env.supabase'
if (-not (Test-Path $saved)) { Copy-Item $EnvFile $saved -ErrorAction Stop; Write-Host "  Previous settings saved as backend\.env.supabase" }
$lines = [Collections.Generic.List[string]](Get-Content $EnvFile -Encoding UTF8)
$values = [ordered]@{
  DB_HOST = '127.0.0.1'; DB_PORT = "$Port"; DB_DATABASE = $Database; DB_USERNAME = 'postgres'
  DB_PASSWORD = $appPw; DB_SSLMODE = 'disable'
}
foreach ($k in $values.Keys) {
  $i = -1
  for ($n = 0; $n -lt $lines.Count; $n++) { if ($lines[$n] -match "^\s*$k\s*=") { $i = $n; break } }
  $line = "$k=$($values[$k])"
  if ($i -ge 0) { $lines[$i] = $line } else { $lines.Add($line) }
}
[IO.File]::WriteAllLines($EnvFile, $lines, (New-Object Text.UTF8Encoding($false)))

# ---------------------------------------------------------------- laravel + data
Push-Location $Backend
try {
  Step "Creating Laravel's tables (php artisan migrate)"
  & $Php artisan migrate --force | Out-Host
  if ($LASTEXITCODE -ne 0) { Fail 'artisan migrate failed.' }

  if ($FromBackup) {
    Step 'Copying all data from the backup'
    $env:PGPASSWORD = $superPw
    Psql $SuperUser $Database @('-c', "DO `$`$ DECLARE t text; BEGIN SELECT string_agg(format('%I.%I', schemaname, tablename), ', ') INTO t FROM pg_tables WHERE schemaname IN ('public','laravel'); EXECUTE 'TRUNCATE ' || t || ' RESTART IDENTITY CASCADE'; END `$`$;")
    & (Join-Path $PgBin 'pg_restore.exe') -h 127.0.0.1 -p $Port -U $SuperUser -d $Database --data-only --disable-triggers --no-owner --exit-on-error -n public -n laravel $FromBackup
    if ($LASTEXITCODE -ne 0) { Fail 'Restoring the data failed.' }
    # Migrations the backup predates (e.g. 0047) are re-applied; they are idempotent.
    $env:PGPASSWORD = $appPw
    Psql 'postgres' $Database @('-f', (Join-Path $SqlDir '0047_services_rls.sql'))
  }

  Step 'Health check (php artisan nasery:doctor)'
  & $Php artisan nasery:doctor | Out-Host
} finally {
  Pop-Location
  Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
  $superPw = $null; $appPw = $null
}

Write-Host "`nDone. NaSeRy now uses the local database (127.0.0.1:$Port)." -ForegroundColor Green
Write-Host 'Restart Apache and the queue worker so they pick up the new settings.'
Write-Host 'Start/stop the database with backend\database\local\start-local-db.ps1 / stop-local-db.ps1.'
Write-Host 'Switch back to Supabase: copy backend\.env.supabase over backend\.env.'
