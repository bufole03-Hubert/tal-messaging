@echo off
chcp 65001 >nul
cd /d "%~dp0"
title TAL Messaging - Installation

rem --- Recherche de PHP (PATH puis XAMPP) ---
set "PHP=php"
where php >nul 2>nul
if errorlevel 1 (
  if exist "C:\xampp\php\php.exe" (
    set "PHP=C:\xampp\php\php.exe"
  ) else (
    echo PHP introuvable. Installez XAMPP ou ajoutez php.exe au PATH.
    pause
    exit /b 1
  )
)

if not exist ".env" (
  echo Creation du fichier .env...
  "%PHP%" scripts\init-env.php
)

echo.
echo === 1/3 Migrations ===
"%PHP%" database\migrate.php
if errorlevel 1 goto erreur

echo.
echo === 2/3 Donnees initiales (roles, permissions, comptes) ===
"%PHP%" database\seed.php
if errorlevel 1 goto erreur

echo.
echo === 3/3 Diagnostic ===
"%PHP%" scripts\doctor.php

echo.
echo Termine. Ouvrez http://localhost/tal-messaging/public/
pause
exit /b 0

:erreur
echo.
echo Une etape a echoue. Verifiez que MySQL est demarre dans XAMPP, que la base "tal_messaging"
echo existe (phpMyAdmin) et les lignes DB_USER / DB_PASSWORD du fichier .env.
"%PHP%" scripts\doctor.php
pause
exit /b 1
