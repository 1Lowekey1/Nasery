@echo off
rem Runs Composer with NaSeRy's PHP 8.5.10 (see php.cmd).
rem Override with NASERY_PHP / NASERY_COMPOSER.
rem   cmd:         composer test
rem   PowerShell:  .\composer test
if not defined NASERY_PHP set "NASERY_PHP=C:\xampp\php8.5.10\php.exe"
if not defined NASERY_COMPOSER set "NASERY_COMPOSER=C:\composer\composer.phar"
if not exist "%NASERY_COMPOSER%" (
  echo NaSeRy: composer.phar not found at "%NASERY_COMPOSER%". Set NASERY_COMPOSER. 1>&2
  exit /b 1
)
call "%~dp0php.cmd" "%NASERY_COMPOSER%" %*
