#!/bin/sh
set -e

# Render injecte la variable PORT et attend que le service ecoute dessus.
# On fait ecouter Caddy en HTTP sur ce port (le proxy Render gere le TLS).
if [ -n "$PORT" ] && [ -z "$SERVER_NAME" ]; then
	export SERVER_NAME=":$PORT"
fi

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	php bin/console -V
fi

# Worker Remboursement embarque dans le conteneur web : les pieces sont en base, donc
# pas besoin de disque partage ni de service worker separe. Ne tourne QUE quand on
# demarre le serveur web (pas pour les commandes ponctuelles : migrations, etc.).
# Boucle de relance : consomme 1h puis repart, et repart aussi apres un crash.
# Desactivable via REMBOURSEMENT_WORKER_OFF=1 (ex. si un worker dedie est cree plus tard).
if [ "$1" = 'frankenphp' ] && [ "$REMBOURSEMENT_WORKER_OFF" != '1' ]; then
	( while true; do
		php bin/console messenger:consume remboursement --time-limit=3600 --memory-limit=192M --no-interaction -v || true
		sleep 2
	done ) &
fi

exec docker-php-entrypoint "$@"
