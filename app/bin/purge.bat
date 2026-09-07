@echo off
REM Purge mensuelle des donnees obsoletes du recouvrement (blobs email expires,
REM PDF soldes, runs termines) - lancee par la tache planifiee "Recouvrement-Purge"
REM le 1er de chaque mois. --force execute reellement (sans : simulation).
REM Sortie journalisee dans var\log\purge.log.
cd /d "C:\chemin\vers\application"
echo ===== %date% %time% ===== >> var\log\purge.log
"C:\php\php.exe" bin\console app:recouvrement:purge --force >> var\log\purge.log 2>&1
