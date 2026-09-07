// Coque de la plateforme : chargement, navigation, recherche transverse,
// theme, reinitialisation. Une seule identite visuelle pour les dix outils.

import { manifest, table } from './donnees.js';
import * as session from './session.js';
import { el, echapper, nb } from './ui.js';
import { CHAINE, OUTILS } from '../catalogue/catalogue.js';
import { pageAccueil, pageOutil, pageObjet, pageRecherche, pageAvantApres, pageTransposer, pageMethode, pageVisite } from '../pages/pages.js';
import { pageResultats } from '../pages/resultats.js';

const vue = document.getElementById('vue');
const chargement = document.getElementById('chargement');
const etatChargement = document.getElementById('chargement-etat');
const barre = document.querySelector('.chargement__barre i');

export const chrono = { demarrage: performance.now(), pretEn: 0 };

// ------------------------------------------------------------------ routes
const ROUTES = [
  [/^\/$/, () => pageAccueil()],
  [/^\/visite\/?(\d+)?$/, (m) => pageVisite(Number(m[1] || 1))],
  [/^\/outils\/([\w-]+)$/, (m) => pageOutil(m[1])],
  [/^\/objet\/([\w-]+)$/, (m) => pageObjet(m[1])],
  [/^\/recherche$/, () => pageRecherche(new URLSearchParams(location.search).get('q') || '')],
  [/^\/resultats$/, () => pageResultats()],
  [/^\/avant-apres$/, () => pageAvantApres()],
  [/^\/transposer$/, () => pageTransposer()],
  [/^\/methode$/, () => pageMethode()],
];

export function aller(chemin, remplacer = false) {
  if (remplacer) history.replaceState({}, '', chemin);
  else history.pushState({}, '', chemin);
  rendre();
}

async function rendre() {
  const chemin = location.pathname.replace(/\/+$/, '') || '/';
  const trouve = ROUTES.find(([r]) => r.test(chemin));
  vue.replaceChildren(el('<div class="carte"><p class="mesure">Calcul…</p></div>'));
  window.scrollTo(0, 0);
  try {
    const contenu = trouve
      ? await trouve[1](chemin.match(trouve[0]))
      : el(`<div class="carte"><h1>Page introuvable</h1><p>Le chemin <code>${echapper(chemin)}</code> ne correspond à aucun écran. <a href="/">Revenir à l'accueil</a>.</p></div>`);
    vue.replaceChildren(contenu);
  } catch (e) {
    vue.replaceChildren(el(`<div class="carte"><h1>Erreur d'affichage</h1><div class="formule">${echapper(e.stack || e.message)}</div></div>`));
  }
  majNav(chemin);
}

function majNav(chemin) {
  for (const a of document.querySelectorAll('#nav a')) {
    a.classList.toggle('actif', a.getAttribute('href') === chemin);
  }
}

// ------------------------------------------------------------- navigation
function construireNav() {
  const nav = document.getElementById('nav');
  const liens = [
    ['/', 'Accueil'], ['/visite', 'Visite guidée'], ['/resultats', 'Résultats'],
    ['/avant-apres', 'Avant / après'], ['/transposer', 'Pour le confrère'], ['/methode', 'Méthode'],
  ];
  nav.replaceChildren(...liens.map(([h, t]) => el(`<a href="${h}">${t}</a>`)));
}

// Un seul intercepteur pour tous les liens internes.
document.addEventListener('click', (ev) => {
  const a = ev.target.closest('a[href^="/"]');
  if (!a || a.target === '_blank' || ev.metaKey || ev.ctrlKey) return;
  ev.preventDefault();
  aller(a.getAttribute('href'));
});
window.addEventListener('popstate', rendre);

// -------------------------------------------------------- recherche transverse
const PREFIXES = {
  TX: 'Virement reçu', FAC: 'Facture', ECR: 'Écriture comptable', CLI: 'Compte client',
  VEH: 'Véhicule', DLV: 'Dossier de livraison', RBC: 'Remboursement, rachat',
  TPC: 'Remboursement, trop-perçu', CMT: 'Décision de comité', REL: 'Ligne de relance',
  BBK: 'Engagement de reprise', ETB: 'Établissement', SOC: 'Société', ORD: 'Ordre de réparation',
};

let indexRecherche = null;

async function construireIndexRecherche() {
  if (indexRecherche) return indexRecherche;
  const [clients, vehicules, etablissements] = await Promise.all([
    table('ref_client'), table('ref_vehicule'), table('ref_etablissement'),
  ]);
  indexRecherche = { clients, vehicules, etablissements };
  return indexRecherche;
}

async function suggerer(q) {
  const requete = q.trim().toUpperCase();
  if (requete.length < 2) return [];
  const sorties = [];

  // Identifiant direct : la fiche universelle sait tout ouvrir.
  const prefixe = requete.split('-')[0];
  if (PREFIXES[prefixe] && requete.length >= 5) {
    sorties.push({ id: requete, libelle: PREFIXES[prefixe], href: `/objet/${requete}` });
  }

  const { clients, vehicules, etablissements } = await construireIndexRecherche();
  const limite = 6;
  for (const c of clients) {
    if (sorties.length > 14) break;
    if (c.nom.toUpperCase().includes(requete) || c.code_balance.includes(requete) || c.id.includes(requete)) {
      sorties.push({ id: c.id, libelle: `${c.nom} · compte client ${c.type}`, href: `/objet/${c.id}` });
    }
  }
  let n = 0;
  for (const v of vehicules) {
    if (n >= limite) break;
    if (v.vin.includes(requete) || v.immatriculation.toUpperCase().includes(requete)) {
      sorties.push({ id: v.immatriculation, libelle: `${v.modele} · n° de série ${v.vin}`, href: `/objet/${v.id}` });
      n += 1;
    }
  }
  for (const e of etablissements) {
    if (e.nom.toUpperCase().includes(requete)) {
      sorties.push({ id: e.id, libelle: `${e.nom} · établissement`, href: `/objet/${e.id}` });
    }
  }
  return sorties.slice(0, 12);
}

function brancherRecherche() {
  const champ = document.getElementById('rech-champ');
  const boite = document.getElementById('rech-suggestions');
  let minuteur = null;

  const fermer = () => { boite.hidden = true; };

  champ.addEventListener('input', () => {
    clearTimeout(minuteur);
    minuteur = setTimeout(async () => {
      const t0 = performance.now();
      const r = await suggerer(champ.value);
      const ms = performance.now() - t0;
      if (!r.length) { fermer(); return; }
      boite.replaceChildren(...r.map((s) => el(
        `<a href="${s.href}"><span class="rech__id">${echapper(s.id)}</span> <span class="rech__lib">${echapper(s.libelle)}</span></a>`)),
        el(`<a href="/recherche?q=${encodeURIComponent(champ.value)}" style="background:var(--carte-2)"><span class="rech__lib">Voir tous les résultats · recherche en ${ms.toFixed(0)} ms</span></a>`));
      boite.hidden = false;
    }, 110);
  });

  champ.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') fermer(); });
  document.getElementById('rech-globale').addEventListener('submit', (ev) => {
    ev.preventDefault(); fermer();
    aller(`/recherche?q=${encodeURIComponent(champ.value)}`);
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.rech')) fermer(); });
}

// --------------------------------------------------------------- theme
function brancherTheme() {
  const cle = 'suite-creances:theme';
  const appliquer = (t) => {
    document.documentElement.dataset.theme = t;
    try { localStorage.setItem(cle, t); } catch { /* navigation privee */ }
  };
  try {
    const memorise = localStorage.getItem(cle);
    if (memorise) document.documentElement.dataset.theme = memorise;
  } catch { /* rien */ }
  document.getElementById('btn-theme').addEventListener('click', () => {
    appliquer(document.documentElement.dataset.theme === 'sombre' ? 'clair' : 'sombre');
  });
}

// --------------------------------------------------------- reinitialisation
function brancherReset() {
  const bouton = document.getElementById('btn-reset');
  const maj = () => {
    const n = session.nbActions();
    bouton.textContent = n ? `↻ Réinitialiser (${n})` : '↻ Réinitialiser';
    bouton.classList.toggle('btn--prim', n > 0);
  };
  session.surChangement(maj);
  maj();
  bouton.addEventListener('click', () => {
    session.reinitialiser();
    rendre();
  });
}

// ------------------------------------------------------------- demarrage
async function demarrer() {
  const etapes = [
    ['Lecture du manifeste des tables', () => manifest()],
    ['Chargement du référentiel', () => Promise.all([table('ref_societe'), table('ref_concession'), table('ref_etablissement'), table('ref_marque'), table('ref_loueur'), table('ref_financeur'), table('ref_banque'), table('ref_utilisateur')])],
    ['Chargement des comptes clients', () => table('ref_client')],
    ['Chargement des factures', () => table('ops_facture')],
    ['Chargement des virements reçus', () => table('ops_virement')],
  ];
  for (let i = 0; i < etapes.length; i++) {
    etatChargement.textContent = etapes[i][0];
    barre.style.width = `${((i + 0.5) / etapes.length) * 100}%`;
    await etapes[i][1]();
  }
  barre.style.width = '100%';

  const info = await manifest();
  document.getElementById('pied-graine').textContent =
    `${info.groupe} · graine ${info.graine} · période ${info.periode[0].split('-').reverse().join('/')} au ${info.periode[1].split('-').reverse().join('/')}`;

  construireNav();
  brancherRecherche();
  brancherTheme();
  brancherReset();

  chrono.pretEn = performance.now() - chrono.demarrage;
  // Mesure REELLE, affichee telle quelle. Aucune performance n'est annoncee.
  const pret = document.createElement('span');
  pret.className = 'mesure';
  pret.id = 'pied-pret';
  pret.textContent = `plateforme prête en ${chrono.pretEn.toFixed(0)} ms sur cet appareil`;
  document.getElementById('pied').append(el('<span class="pied__sep">·</span>'), pret);
  chargement.remove();
  for (const n of ['entete', 'vue', 'pied']) document.getElementById(n).hidden = false;
  await rendre();
  console.log(`Plateforme prête en ${chrono.pretEn.toFixed(0)} ms · ${nb(Object.values(info.tables).reduce((s, x) => s + x, 0))} lignes disponibles`);
}

demarrer().catch((e) => {
  etatChargement.textContent = `Échec du chargement : ${e.message}`;
  etatChargement.style.color = 'var(--rouge)';
});
