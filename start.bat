@echo off
rem ---------------------------------------------------------------------
rem  NaSeRy: start everything (double-click this, or a desktop shortcut)
rem
rem  It asks how to start (1 is the default after 15 seconds):
rem    1  The real site for everyone: production mode + Cloudflare link
rem       (same as "start.bat public")
rem    2  This PC only, for development: http://localhost:3000
rem       (same as "start.bat dev")
rem  "start.bat public rebuild" forces a fresh build of the website.
rem
rem  Each server opens its own "NaSeRy - ..." window: leave them open.
rem  Anything already running is left alone, so starting twice is safe.
rem  To stop: stop.bat. The logic lives in scripts\nasery-start.ps1.
rem ---------------------------------------------------------------------
setlocal
title NaSeRy
set "SYS=%SystemRoot%\System32"
set "MODE="
set "EXTRA="
for %%A in (%*) do (
  if /I "%%~A"=="public" set "MODE=public"
  if /I "%%~A"=="dev" set "MODE=dev"
  if /I "%%~A"=="rebuild" set "EXTRA=-Rebuild"
)
if defined MODE goto chosen
echo.
echo  How do you want to start NaSeRy?
echo    1  The real site for everyone (Cloudflare link)
echo    2  This PC only (development)
echo.
"%SYS%\choice.exe" /C 12 /N /T 15 /D 1 /M "  Press 1 or 2 (starts with 1 in 15 seconds): "
if errorlevel 2 (set "MODE=dev") else (set "MODE=public")
:chosen
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\nasery-start.ps1" -Mode %MODE% %EXTRA%
endlocal
