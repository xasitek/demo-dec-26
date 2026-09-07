@echo off
setlocal
title Finance Creances - demonstration DEC
cd /d "%~dp0"
echo.
echo   Finance Creances - demonstration DEC
echo   Donnees entierement synthetiques. Aucune connexion externe.
echo.

where php >nul 2>nul || (echo   PHP est introuvable. & pause & exit /b 1)
where docker >nul 2>nul || (echo   Docker est introuvable. & pause & exit /b 1)

docker start finance-demo-db >nul 2>nul
if errorlevel 1 (
  echo   Creation de la base de demonstration sur le port 55432...
  docker run -d --name finance-demo-db -e POSTGRES_DB=finance_demo -e POSTGRES_USER=demo -e POSTGRES_PASSWORD=demo -p 127.0.0.1:55432:5432 postgres:16-alpine >nul
  timeout /t 12 /nobreak >nul
  php bin\console doctrine:migrations:migrate --no-interaction
  php bin\console app:demo:preparer
  php bin\console app:remboursement:donnees-demo --force
  php bin\console app:creances:seed-demo --confirm
)

if not exist "vendor\autoload.php" ( echo   Installation des dependances... & composer install --no-interaction )
if not exist "public\assets\manifest.json" ( php bin\console tailwind:build & php bin\console asset-map:compile )

echo   Demarrage sur http://localhost:8123/
start "" http://localhost:8123/
php -S 127.0.0.1:8123 -t public demo-routeur.php
pause
