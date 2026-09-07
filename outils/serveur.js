// Micro-serveur STRICTEMENT STATIQUE, pour le mode hors ligne.
//
// Il ne fait rien d'autre que servir les fichiers de public/ : aucune logique
// applicative, aucune base, aucune sortie reseau. Il existe parce qu'un
// navigateur ouvrant index.html en file:// refuse les modules et les fetch.
//
//   node outils/serveur.js [port]

import { createServer } from 'node:http';
import { createReadStream, existsSync, statSync } from 'node:fs';
import { extname, join, normalize, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createGzip } from 'node:zlib';
import { spawn } from 'node:child_process';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', 'public');
const PORT = Number(process.argv[2] || process.env.PORT || 4180);
const OUVRIR = process.argv.includes('--sans-navigateur') ? false : true;

const TYPES = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon',
  '.woff2': 'font/woff2', '.txt': 'text/plain; charset=utf-8', '.map': 'application/json',
};
const COMPRESSIBLES = new Set(['.html', '.js', '.css', '.json', '.svg', '.txt']);

const serveur = createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  let chemin = decodeURIComponent(url.pathname);
  if (chemin.endsWith('/')) chemin += 'index.html';

  let fichier = join(RACINE, normalize(chemin).replace(/^(\.\.[/\\])+/, ''));
  if (!fichier.startsWith(RACINE)) { res.writeHead(403).end('Interdit'); return; }

  // Les adresses de la plateforme sont des chemins reels : /outils/4-affectation.
  // Toute adresse sans extension retombe sur index.html, qui route lui-meme.
  if (!existsSync(fichier) || statSync(fichier).isDirectory()) {
    if (extname(fichier)) { res.writeHead(404, { 'content-type': 'text/plain' }).end('Introuvable'); return; }
    fichier = join(RACINE, 'index.html');
  }

  const ext = extname(fichier);
  const entetes = {
    'content-type': TYPES[ext] || 'application/octet-stream',
    'cache-control': 'no-cache',
    'x-robots-tag': 'noindex, nofollow',
  };

  const accepteGzip = /\bgzip\b/.test(req.headers['accept-encoding'] || '');
  if (accepteGzip && COMPRESSIBLES.has(ext)) {
    entetes['content-encoding'] = 'gzip';
    res.writeHead(200, entetes);
    createReadStream(fichier).pipe(createGzip({ level: 6 })).pipe(res);
  } else {
    entetes['content-length'] = statSync(fichier).size;
    res.writeHead(200, entetes);
    createReadStream(fichier).pipe(res);
  }
});

serveur.listen(PORT, '127.0.0.1', () => {
  const adresse = `http://localhost:${PORT}/`;
  console.log('');
  console.log('  Suite Créances Automobile — mode hors ligne');
  console.log('  ------------------------------------------------');
  console.log(`  Ouvrez : ${adresse}`);
  console.log('  Serveur strictement statique, aucune sortie réseau.');
  console.log('  Ctrl+C pour arrêter.');
  console.log('');
  if (OUVRIR) {
    const cmd = process.platform === 'win32' ? ['cmd', ['/c', 'start', '', adresse]]
      : process.platform === 'darwin' ? ['open', [adresse]] : ['xdg-open', [adresse]];
    try { spawn(cmd[0], cmd[1], { detached: true, stdio: 'ignore' }).unref(); } catch { /* ouverture manuelle */ }
  }
});
