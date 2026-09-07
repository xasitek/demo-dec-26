@echo off
setlocal
title Suite Creances Automobile - demonstration hors ligne
cd /d "%~dp0"

echo.
echo   Suite Creances Automobile
echo   Demonstration hors ligne. Aucune connexion n'est utilisee.
echo.

where node >nul 2>nul
if errorlevel 1 (
  echo   Node.js est introuvable sur cette machine.
  echo   Installez-le depuis nodejs.org, puis relancez ce fichier.
  echo.
  pause
  exit /b 1
)

if not exist "public\donnees\monde\index.json" (
  echo   Les donnees de demonstration ne sont pas encore fabriquees.
  echo   Fabrication en cours, une seule fois...
  echo.
  node fabrique\construire.js
  if errorlevel 1 (
    echo   La fabrication a echoue.
    pause
    exit /b 1
  )
)

echo   Demarrage du serveur local...
node outils\serveur.js
pause
