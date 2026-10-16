@echo off
rem ---------------------------------------------------------------------
rem  NaSeRy: back up the database to backend\storage\backups
rem  (nasery:db:backup; keeps the newest copies, older ones are pruned).
rem  Double-click any time, or let Windows Task Scheduler run
rem  "backup.bat quiet" every day.
rem
rem  Copy that folder somewhere else now and then (USB, Google Drive):
rem  a backup on the same PC doesn't survive a dead drive.
rem ---------------------------------------------------------------------
setlocal
cd /d "%~dp0backend"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0backend\database\local\start-local-db.ps1" >nul
"C:\xampp\php8.5.10\php.exe" artisan nasery:db:backup --reason=daily
if errorlevel 1 (
  echo  [!] Backup failed.
  if /I not "%~1"=="quiet" pause
  exit /b 1
)
if /I not "%~1"=="quiet" "%SystemRoot%\System32\timeout.exe" /t 5 >nul
endlocal
