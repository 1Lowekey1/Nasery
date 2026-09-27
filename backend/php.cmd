@echo off
rem NaSeRy runs on PHP 8.5.10 without changing the machine's default PHP.
rem Override the location with the NASERY_PHP environment variable.
rem   cmd:         php artisan serve
rem   PowerShell:  .\php artisan serve
if not defined NASERY_PHP set "NASERY_PHP=C:\xampp\php8.5.10\php.exe"
if not exist "%NASERY_PHP%" (
  echo NaSeRy: PHP 8.5 not found at "%NASERY_PHP%". Set NASERY_PHP to your php.exe. 1>&2
  exit /b 1
)
"%NASERY_PHP%" %*
