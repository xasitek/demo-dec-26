#!/bin/sh
# Suite Creances Automobile. Demonstration hors ligne, aucune connexion utilisee.
cd "$(dirname "$0")" || exit 1

if ! command -v node >/dev/null 2>&1; then
  echo "  Node.js est introuvable. Installez-le depuis nodejs.org, puis relancez."
  exit 1
fi

if [ ! -f public/donnees/monde/index.json ]; then
  echo "  Fabrication des donnees de demonstration, une seule fois..."
  node fabrique/construire.js || exit 1
fi

echo "  Demarrage du serveur local..."
exec node outils/serveur.js
