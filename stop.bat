@echo off
rem ---------------------------------------------------------------------
rem  NaSeRy: stop the website, API, tunnel, queue worker and scheduler.
rem  The database keeps running (it is harmless and starts faster next
rem  time). "stop.bat all" stops the database too.
rem ---------------------------------------------------------------------
title Stop NaSeRy
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\nasery-stop.ps1" %*
"%SystemRoot%\System32\timeout.exe" /t 4 >nul
