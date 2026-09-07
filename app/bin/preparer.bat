@echo off
REM Preparation quotidienne des relances (recouvrement) - tache "Recouvrement-Preparer" (07h30,
REM apres l'ETL). Rafraichit v_impayes puis depose les relances eligibles dans la file ;
REM le worker (service) les envoie. Tant que RECOUVREMENT_FORCE_TO est rempli, tout part sur
REM cette adresse (aucun vrai client). --limit borne le volume pendant la montee en charge :
REM l'augmenter puis le retirer une fois confiant (plafond 500/run sans limite).
REM Sortie journalisee dans var\log\preparer.log.
REM -d memory_limit=1G : preparer charge toute la selection v_impayes en RAM (OOM a 128 Mo par defaut).
cd /d "C:\chemin\vers\application"
echo ===== %date% %time% ===== >> var\log\preparer.log
"C:\php\php.exe" -d memory_limit=1G bin\console app:recouvrement:preparer --limit=25 >> var\log\preparer.log 2>&1
REM Genere le PDF complet (releve + factures) des courriers papier prepares. Sage
REM et Ghostscript ne sont dispo QUE sur cette machine interne ; le web
REM (Render) sert ensuite le PDF stocke (relance_envoi.courrier_pdf) tel quel. Requiert
REM un cache prod a jour (le reconstruire apres chaque git pull, comme pour le worker).
"C:\php\php.exe" -d memory_limit=1G bin\console app:recouvrement:generer-courriers >> var\log\preparer.log 2>&1
