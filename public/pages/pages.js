// Les ecrans de la suite. Meme structure partout : sept blocs par outil,
// une seule fiche DONNEE -> CONTROLE -> DECISION -> IMPACT.

import { table, parId, groupePar, manifest } from '../socle/donnees.js';
import { carteKpi, el, echapper, nb, euro, euroCourt, date, tableau, barres, paires, ouvrirModale, pourcent } from '../socle/ui.js';
import { repartir, somme } from '../socle/kpi.js';
import { CHAINE, OUTILS, REGLES, TRANSPOSITION, cleParNumero } from '../catalogue/catalogue.js';
import * as session from '../socle/session.js';

// =========================================================== accueil

export async function pageAccueil() {
  const info = await manifest();
  const total = Object.values(info.tables).reduce((s, x) => s + x, 0);

  const racine = el(`<div>
    <section class="hero">
      <h1>Suite de pilotage du cycle créances automobile</h1>
      <p>Dix outils pour diagnostiquer, fiabiliser et piloter le cycle <i>order-to-cash</i>
         d'un groupe multimarques et multi-concessions.</p>
      <div class="hero__actions">
        <a class="btn btn--prim btn--grand" href="/visite">Découvrir en 5 minutes</a>
        <a class="btn btn--grand" href="/resultats">Voir les résultats</a>
      </div>
    </section>

    <div class="note note--info" style="margin-bottom:1.1rem">
      <b>Vous pouvez tout essayer.</b> Rien n'est enregistré, chaque visiteur travaille sur son
      propre exemplaire, et le bouton <b>↻ Réinitialiser</b> remet la démonstration en place
      en un clic. Il est impossible de l'abîmer pour quelqu'un d'autre.
    </div>

    <div class="chaine" data-role="chaine"></div>
    <div class="flux" data-role="flux"></div>

    <div class="carte" style="margin-top:1.4rem">
      <div class="carte__titre"><h2>L'environnement de démonstration</h2>
        <small>${nb(total)} lignes, toutes fabriquées, aucune donnée client</small></div>
      <div class="grille g4" data-role="kpis"></div>
    </div>
  </div>`);

  racine.querySelector('[data-role="chaine"]').replaceChildren(...CHAINE.map((b) => el(`
    <div class="bloc">
      <div class="bloc__tit">${echapper(b.titre)}</div>
      ${b.outils.map((o) => `<a class="outil" href="/outils/${o.cle}"><b>${o.n}</b><span>${echapper(o.nom)}</span></a>`).join('')}
    </div>`)));

  racine.querySelector('[data-role="flux"]').replaceChildren(fluxAnime());

  racine.querySelector('[data-role="kpis"]').replaceChildren(
    carteKpi('SOC-ECRITURES', { ton: 'accent' }),
    carteKpi('SOC-FACTURES'),
    carteKpi('SOC-VIREMENTS'),
    carteKpi('SOC-ENCOURS', { ton: 'or' }),
    carteKpi('SOC-CLIENTS'),
    carteKpi('SOC-VEHICULES'),
    carteKpi('SOC-REMBOURSEMENTS'),
    carteKpi('SOC-COMITES'),
  );
  return racine;
}

/** La donnee circule entre les outils : on le montre avant d'avoir clique. */
function fluxAnime() {
  const etapes = [4, 5, 6, 7, 9, 10];
  const largeur = 1000, y = 40;
  const pas = largeur / (etapes.length + 1);
  const noeuds = etapes.map((n, i) => ({ n, x: pas * (i + 1) }));
  return el(`<svg viewBox="0 0 ${largeur} 80" role="img" aria-label="La donnée circule d'un outil à l'autre">
    <line x1="${noeuds[0].x}" y1="${y}" x2="${noeuds.at(-1).x}" y2="${y}"
          stroke="var(--bord-fort)" stroke-width="2"/>
    ${noeuds.map((p) => `
      <circle cx="${p.x}" cy="${y}" r="15" fill="var(--carte)" stroke="var(--bord-fort)" stroke-width="2"/>
      <text x="${p.x}" y="${y + 4.5}" text-anchor="middle" font-size="13" font-weight="600"
            fill="var(--texte-2)" font-family="var(--mono)">${p.n}</text>
      <text x="${p.x}" y="${y + 32}" text-anchor="middle" font-size="10.5" fill="var(--texte-3)">${echapper(OUTILS[cleParNumero(p.n)].nom.split(' ')[0])}</text>`).join('')}
    <circle r="6" fill="var(--or)">
      <animateMotion dur="7s" repeatCount="indefinite"
        path="M ${noeuds[0].x} ${y} L ${noeuds.at(-1).x} ${y}" keyPoints="0;0;0.2;0.2;0.4;0.4;0.6;0.6;0.8;0.8;1;1"
        keyTimes="0;0.05;0.2;0.25;0.4;0.45;0.6;0.65;0.8;0.85;0.97;1" calcMode="linear"/>
    </circle>
  </svg>`);
}

// =========================================================== page d'outil

const APERCUS = {
  4: { table: 'ops_virement', titre: 'Virements reçus à identifier' },
  5: { table: 'ops_ecriture', titre: 'Écritures du compte client' },
  6: { table: 'ops_facture', titre: 'Factures du périmètre' },
  7: { table: 'ops_dossier_livraison', titre: 'Dossiers de livraison grands comptes' },
  8: { table: 'ops_dossier_remboursement', titre: 'Demandes de remboursement' },
  9: { table: 'ops_decision_comite', titre: 'Décisions de comité' },
  10: { table: 'ops_ligne_relance', titre: 'Portefeuille de relance' },
};

/**
 * Les « cas à découvrir » sont curatés à la fabrication et pointent chacun sur
 * un objet réel du jeu. Métadonnée d'auteur : aucun moteur ne la lit.
 */
let vitrine = null;
async function casADecouvrir(cle) {
  if (!vitrine) vitrine = await (await fetch(new URL('../donnees/vitrine.json', import.meta.url))).json();
  return vitrine[cle] || [];
}

export async function pageOutil(cle) {
  const o = OUTILS[cle];
  if (!o) return el('<div class="carte"><h1>Outil inconnu</h1></div>');
  const cas = await casADecouvrir(cle);

  const racine = el(`<div>
    <div class="fil"><a href="/">Accueil</a> › Outil ${o.n}</div>
    <h1 style="margin-bottom:1.1rem">${o.n}. ${echapper(o.nom)}</h1>
    ${cas.length ? `<h3 style="margin:.2rem 0 .6rem;color:var(--texte-2);font-size:.8rem;letter-spacing:.08em;text-transform:uppercase">Cas à découvrir</h3>
      <div class="cas" style="margin-bottom:1.4rem">${cas.map((c) => `
        <a href="/objet/${c.id}"><b>${echapper(c.titre)}</b><small>${echapper(c.sous)}</small></a>`).join('')}</div>` : ''}
    <div class="sept">
      <section><h2>Le problème</h2><p>${echapper(o.probleme)}</p></section>
      <section><h2>Ce que fait l'outil</h2><p>${echapper(o.role)}</p></section>
      <section><h2>Le résultat</h2><div class="grille g3" data-role="kpis"></div></section>
      <section><h2>Essayer</h2><div data-role="essayer"></div></section>
      <section><h2>Pourquoi cette décision</h2><div data-role="pourquoi"></div></section>
      <section><h2>Avant / après</h2><div data-role="avant"></div></section>
      <section><h2>Méthode</h2><div data-role="methode"></div></section>
    </div>
  </div>`);

  racine.querySelector('[data-role="kpis"]').replaceChildren(
    ...o.kpis.map((id, i) => carteKpi(id, { ton: i === 0 ? 'accent' : undefined })));

  // Bloc 4 : les lignes reelles de l'outil. Le moteur se branche au lot suivant.
  const ap = APERCUS[o.n];
  const essayer = racine.querySelector('[data-role="essayer"]');
  if (ap) {
    const t0 = performance.now();
    const lignes = await table(ap.table);
    const ms = performance.now() - t0;
    const colonnes = Object.keys(lignes[0]).slice(0, 8).map((c) => ({
      titre: c.replace(/_/g, ' '),
      valeur: (l) => (typeof l[c] === 'number' ? nb(l[c]) : (l[c] ?? '—')),
      classe: typeof lignes[0][c] === 'number' ? 'num' : (c === 'id' ? 'id' : ''),
    }));
    essayer.replaceChildren(
      el(`<p>${echapper(ap.titre)} : <b>${nb(lignes.length)}</b> lignes chargées en <b>${ms.toFixed(0)} ms</b>, mesuré sur cet appareil. Les deux cents premières sont affichées.</p>`),
      tableau(lignes, colonnes, { limite: 200 }),
      el(`<p class="mesure" style="margin-top:.6rem">Table ${ap.table} · le moteur de décision de cet outil est branché au lot suivant. Les lignes, elles, sont déjà celles de la démonstration.</p>`),
    );
  } else {
    essayer.replaceChildren(el('<p class="note">Cet outil est un livrable de cadrage : son écran interactif arrive avec son lot.</p>'));
  }

  // Bloc 5 : les regles qui gouvernent l'outil, avec leur reference neutre.
  const regles = REGLES.filter((r) => r.outil === o.n);
  racine.querySelector('[data-role="pourquoi"]').replaceChildren(
    regles.length
      ? el(`<div>${regles.map((r) => `
          <div style="padding:.55rem 0;border-bottom:1px solid var(--bord)">
            <div style="display:flex;gap:.6rem;align-items:baseline">
              <span class="past past--gris" style="font-family:var(--mono)">${r.ref}</span>
              <b>${echapper(r.nom)}</b>
            </div>
            <p style="margin:.3rem 0 0;color:var(--texte-2)">${echapper(r.enonce)}</p>
          </div>`).join('')}
          <p class="mesure" style="margin-top:.7rem">Chaque règle est déclarée, pas enfouie. Aucune décision de la suite n'est prise sans qu'une règle nommée puisse en rendre compte.</p>
        </div>`)
      : el('<p class="note">Les règles de cet outil sont déclarées avec son lot.</p>'),
  );

  racine.querySelector('[data-role="avant"]').replaceChildren(el(`<div class="grille g2">
    <div><div class="fiche__eti">Avant</div><p style="color:var(--texte-2)">${echapper(o.probleme)}</p></div>
    <div><div class="fiche__eti">Après</div><p>${echapper(o.role)}</p></div>
  </div>`));

  racine.querySelector('[data-role="methode"]').replaceChildren(el(`<div>
    ${paires([
      ['Origine de la logique', o.origine],
      ['Données', ap ? `table ${ap.table}, entièrement fabriquée` : 'référentiel de la mission'],
      ['Indicateurs', `${o.kpis.length} indicateurs, tous recalculés à l'affichage`],
      ['Règles déclarées', String(regles.length)],
    ])}
    <p class="note note--attention" style="margin-top:.8rem"><b>Limites.</b> Les données de cet écran
    sont fictives. Elles reproduisent les mécanismes métier que l'outil traite, elles ne mesurent
    rien de réel. Les durées affichées, en revanche, sont chronométrées sur votre appareil.</p>
  </div>`));

  return racine;
}

// =================================================== fiche universelle d'objet

const RESOLVEURS = [
  { prefixe: 'TX', table: 'ops_virement', titre: 'Virement reçu' },
  { prefixe: 'FAC', table: 'ops_facture', titre: 'Facture' },
  { prefixe: 'ECR', table: 'ops_ecriture', titre: 'Écriture comptable' },
  { prefixe: 'CLI', table: 'ref_client', titre: 'Compte client' },
  { prefixe: 'VEH', table: 'ref_vehicule', titre: 'Véhicule' },
  { prefixe: 'DLV', table: 'ops_dossier_livraison', titre: 'Dossier de livraison' },
  { prefixe: 'RBC', table: 'ops_dossier_remboursement', titre: 'Remboursement, rachat sec' },
  { prefixe: 'TPC', table: 'ops_dossier_remboursement', titre: 'Remboursement, trop-perçu' },
  { prefixe: 'CMT', table: 'ops_decision_comite', titre: 'Décision de comité' },
  { prefixe: 'REL', table: 'ops_ligne_relance', titre: 'Ligne de relance' },
  { prefixe: 'BBK', table: 'ref_contrat_buyback', titre: 'Engagement de reprise' },
  { prefixe: 'ETB', table: 'ref_etablissement', titre: 'Établissement' },
  { prefixe: 'ORD', table: 'ops_ordre_reparation', titre: 'Ordre de réparation' },
];

export async function pageObjet(objetId) {
  const t0 = performance.now();
  const prefixe = objetId.split('-')[0];
  const r = RESOLVEURS.find((x) => x.prefixe === prefixe);
  if (!r) return el(`<div class="carte"><h1>Identifiant non reconnu</h1><p><code>${echapper(objetId)}</code> ne correspond à aucun objet de la suite.</p></div>`);

  const index = await parId(r.table);
  const objet = index.get(objetId);
  if (!objet) return el(`<div class="carte"><h1>Objet introuvable</h1><p><code>${echapper(objetId)}</code> n'existe pas dans l'environnement de démonstration.</p><p><a href="/recherche?q=${encodeURIComponent(objetId)}">Chercher autrement</a></p></div>`);

  const liens = await traverser(r, objet);
  const ms = performance.now() - t0;

  const racine = el(`<div>
    <div class="fil"><a href="/">Accueil</a> › Fiche d'objet</div>
    <div style="display:flex;gap:.8rem;align-items:baseline;flex-wrap:wrap;margin-bottom:.9rem">
      <h1 style="font-family:var(--mono);font-size:1.5rem">${echapper(objetId)}</h1>
      <span class="past past--accent">${echapper(r.titre)}</span>
      <span class="mesure">traversée de ${liens.length} outils en ${ms.toFixed(0)} ms</span>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Ce que la donnée porte</h2><small>table ${r.table}</small></div>
      ${paires(Object.entries(objet).filter(([k]) => !k.startsWith('_') && k !== 'vedette').map(([k, v]) =>
        [k.replace(/_/g, ' '), typeof v === 'number' ? (k.includes('montant') || k.includes('er_') ? euro(v) : nb(v)) : String(v ?? '—')]))}
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Son passage dans la suite</h2>
        <small>chaque ligne est calculée, pas décrite</small></div>
      <div data-role="liens"></div>
    </div>
  </div>`);

  racine.querySelector('[data-role="liens"]').replaceChildren(
    liens.length
      ? el(`<div>${liens.map((l) => `
        <div style="display:grid;grid-template-columns:5.5rem 1fr auto;gap:.7rem;align-items:baseline;padding:.5rem 0;border-bottom:1px solid var(--bord)">
          <span class="past past--gris" style="justify-self:start">Outil ${l.outil}</span>
          <span>${l.texte}</span>
          ${l.href ? `<a href="${l.href}">ouvrir</a>` : '<span class="mesure">—</span>'}
        </div>`).join('')}</div>`)
      : el('<p class="note">Cet objet n\'a pas encore de trace dans les outils : leurs moteurs se branchent aux lots suivants.</p>'),
  );
  return racine;
}

/** Traversee reelle : chaque lien est le resultat d'une jointure sur les tables. */
async function traverser(r, objet) {
  const liens = [];
  const clients = await parId('ref_client');
  const etabs = await parId('ref_etablissement');

  if (r.prefixe === 'TX') {
    const ibans = await parId('ref_iban');
    const ib = ibans.get(objet.iban_emetteur_id);
    const banques = await parId('ref_banque');
    liens.push({ outil: 4, texte: `Libellé reçu <b>${echapper(objet.libelle)}</b> · ${echapper(banques.get(objet.banque_id).nom)} · IBAN émetteur vu <b>${nb(ib.occurrences)}</b> fois`, href: '/outils/4-affectation' });
    liens.push({ outil: 4, texte: objet.reference_bout_en_bout ? `Référence de bout en bout <b>${echapper(objet.reference_bout_en_bout)}</b>` : `<b>Aucune référence de bout en bout</b> : le moteur devra s'en passer`, href: null });
    const memeMontant = (await table('ops_facture')).filter((f) => Math.abs(f.montant - objet.montant) < 0.01 && f.statut !== 'soldee');
    liens.push({
      outil: 5,
      texte: memeMontant.length
        ? `<b>${nb(memeMontant.length)}</b> facture(s) ouverte(s) du montant exact : le rapprochement peut se tenter à l'unité`
        : `<b>Aucune facture ouverte ne porte ce montant.</b> Il faudra chercher une combinaison de plusieurs factures, ce qu'un lettrage généraliste ne sait pas faire`,
      href: '/outils/5-lettrage',
    });
    liens.push({ outil: 6, texte: `Montant à affecter <b>${euro(objet.montant)}</b>, société ${echapper(objet.societe_id)}`, href: '/outils/6-pilotage' });
  }

  if (r.prefixe === 'FAC') {
    const ecr = (await groupePar('ops_ecriture', 'facture_id')).get(objet.id) || [];
    const nonLettrees = ecr.filter((e) => !e.lettrage);
    liens.push({ outil: 5, texte: `<b>${nb(ecr.length)}</b> écritures rattachées, dont <b>${nb(nonLettrees.length)}</b> non lettrées`, href: '/outils/5-lettrage' });
    const dlv = (await table('ops_dossier_livraison')).filter((d) => d.facture_id === objet.id);
    if (dlv.length) liens.push({ outil: 7, texte: `Dossier de livraison <b>${echapper(dlv[0].id)}</b>`, href: `/objet/${dlv[0].id}` });
    const cmt = (await table('ops_decision_comite')).filter((d) => d.facture_id === objet.id);
    if (cmt.length) liens.push({ outil: 9, texte: `Passée en comité le ${date(cmt[0].date)} · motif <b>${echapper(cmt[0].motif)}</b> · responsable ${echapper(cmt[0].responsable)}`, href: `/objet/${cmt[0].id}` });
    const rel = (await table('ops_ligne_relance')).filter((l) => l.facture_id === objet.id);
    if (rel.length) liens.push({ outil: 10, texte: `Au portefeuille de relance · ancienneté <b>${rel[0].anciennete} j</b> · ${rel[0].relances_deja_envoyees} relance(s) déjà envoyée(s)`, href: `/objet/${rel[0].id}` });
    liens.push({ outil: 6, texte: `Encours porté <b>${euro(objet.montant)}</b> · établissement ${echapper(etabs.get(objet.etablissement_id).nom)}`, href: '/outils/6-pilotage' });
  }

  if (r.prefixe === 'CLI') {
    const fac = (await groupePar('ops_facture', 'client_id')).get(objet.id) || [];
    const ouvertes = fac.filter((f) => f.statut !== 'soldee');
    liens.push({ outil: 6, texte: `<b>${nb(fac.length)}</b> factures, dont <b>${nb(ouvertes.length)}</b> ouvertes pour <b>${euro(somme(ouvertes, (f) => f.montant))}</b>`, href: '/outils/6-pilotage' });
    const rbc = (await table('ops_dossier_remboursement')).filter((d) => d.client_id === objet.id);
    if (rbc.length) liens.push({ outil: 8, texte: `<b>${nb(rbc.length)}</b> demande(s) de remboursement`, href: `/objet/${rbc[0].id}` });
    const cmt = (await table('ops_decision_comite')).filter((d) => d.client_id === objet.id);
    if (cmt.length) liens.push({ outil: 9, texte: `<b>${nb(cmt.length)}</b> passage(s) en comité`, href: `/objet/${cmt[0].id}` });
    if (objet.code_referentiel !== objet.code_balance) {
      liens.push({ outil: 3, texte: `<b>Codes divergents</b> : ${echapper(objet.code_referentiel)} au référentiel, ${echapper(objet.code_balance)} à la balance. Aucune jointure directe n'est possible.`, href: '/outils/3-maturite' });
    }
  }

  if (r.prefixe === 'VEH') {
    const ecr = (await table('ops_ecriture')).filter((e) => e.vin === objet.vin);
    liens.push({ outil: 5, texte: `<b>${nb(ecr.length)}</b> écritures portent ce numéro de série`, href: '/outils/5-lettrage' });
    const ord = (await table('ops_ordre_reparation')).filter((o) => o.vehicule_id === objet.id);
    if (ord.length) liens.push({ outil: 5, texte: `<b>${nb(ord.length)}</b> ordre(s) de réparation`, href: null });
    const bbk = (await table('ref_contrat_buyback')).filter((b) => b.vehicule_id === objet.id);
    if (bbk.length) liens.push({ outil: 8, texte: `Engagement de reprise <b>${euro(bbk[0].er_ttc)}</b> TTC · échéance ${date(bbk[0].echeance)}`, href: `/objet/${bbk[0].id}` });
    const dlv = (await table('ops_dossier_livraison')).filter((d) => d.vehicule_id === objet.id);
    if (dlv.length) liens.push({ outil: 7, texte: `Dossier de livraison <b>${echapper(dlv[0].id)}</b>`, href: `/objet/${dlv[0].id}` });
  }

  if (r.prefixe === 'DLV') {
    const loueurs = await parId('ref_loueur');
    const l = loueurs.get(objet.loueur_id);
    const pieces = (await groupePar('ops_piece_livraison', 'dossier_id')).get(objet.id) || [];
    const manquantes = pieces.filter((p) => !p.presente);
    liens.push({ outil: 7, texte: `Payeur <b>${echapper(l.nom)}</b> · grille exigée : ${l.grille.join(', ')}`, href: '/outils/7-grands-comptes' });
    liens.push({ outil: 7, texte: manquantes.length ? `<b>${manquantes.length} pièce(s) absente(s)</b> : ${manquantes.map((p) => p.type).join(', ')} · <b>${euro(objet.montant)}</b> bloqués` : `Dossier complet · <b>${euro(objet.montant)}</b> payables`, href: null });
    liens.push({ outil: 6, texte: `Facture <b>${echapper(objet.facture_id)}</b>`, href: `/objet/${objet.facture_id}` });
  }

  if (r.prefixe === 'RBC' || r.prefixe === 'TPC') {
    const users = await parId('ref_utilisateur');
    const ibans = await parId('ref_iban');
    const contrats = await parId('ref_contrat_buyback');
    liens.push({ outil: 8, texte: `Déposé par <b>${echapper(users.get(objet.deposant_id).nom)}</b> · instruit par ${echapper(users.get(objet.comptable_id).nom)} · validation attendue de ${echapper(users.get(objet.directeur_id).nom)}`, href: '/outils/8-remboursements' });
    const dir = users.get(objet.directeur_id);
    const dansPerimetre = dir.etablissements.includes(objet.etablissement_id);
    liens.push({ outil: 8, texte: dansPerimetre ? `Le valideur est bien directeur de l'établissement du dossier` : `<b>Le valideur n'est pas directeur de cet établissement</b> : hors périmètre`, href: null });
    if (objet.contrat_buyback_id) {
      const c = contrats.get(objet.contrat_buyback_id);
      const ecart = Math.round((objet.montant_demande - c.er_ttc) * 100) / 100;
      liens.push({ outil: 8, texte: `Engagement de reprise <b>${euro(c.er_ttc)}</b> · demande <b>${euro(objet.montant_demande)}</b> · écart <b>${ecart > 0 ? '+' : ''}${euro(ecart)}</b>`, href: `/objet/${c.id}` });
    }
    const memeEmpreinte = objet.iban_saisi_id !== objet.iban_extrait_id;
    liens.push({ outil: 8, texte: memeEmpreinte ? `<b>L'IBAN de la pièce diffère de l'IBAN saisi</b>` : `IBAN saisi et IBAN lu sur la pièce concordent`, href: null });
    liens.push({ outil: 6, texte: `Montant demandé <b>${euro(objet.montant_demande)}</b> · établissement ${echapper(etabs.get(objet.etablissement_id).nom)}`, href: '/outils/6-pilotage' });
  }

  if (r.prefixe === 'CMT') {
    liens.push({ outil: 9, texte: `Motif <b>${echapper(objet.motif)}</b> · famille ${echapper(objet.famille)} · responsable ${echapper(objet.responsable)}`, href: '/outils/9-comites' });
    liens.push({ outil: 6, texte: `Facture <b>${echapper(objet.facture_id)}</b> · ${euro(objet.montant)}`, href: `/objet/${objet.facture_id}` });
    liens.push({ outil: 10, texte: `Décision <b>${echapper(objet.decision)}</b> · statut ${echapper(objet.statut)}`, href: '/outils/10-relance' });
  }

  if (r.prefixe === 'REL') {
    liens.push({ outil: 10, texte: `Ancienneté <b>${objet.anciennete} j</b> · ${objet.relances_deja_envoyees} relance(s) envoyée(s) · <b>${euro(objet.montant)}</b>`, href: '/outils/10-relance' });
    liens.push({ outil: 6, texte: `Facture <b>${echapper(objet.facture_id)}</b>`, href: `/objet/${objet.facture_id}` });
    liens.push({ outil: 4, texte: `Compte client <b>${echapper(clients.get(objet.client_id).nom)}</b>`, href: `/objet/${objet.client_id}` });
  }

  if (r.prefixe === 'BBK') {
    liens.push({ outil: 8, texte: `Engagement <b>${euro(objet.er_ttc)}</b> TTC · ${euro(objet.er_ht)} HT · échéance ${date(objet.echeance)}`, href: '/outils/8-remboursements' });
    liens.push({ outil: 8, texte: `Véhicule <b>${echapper(objet.immatriculation)}</b> · n° de série ${echapper(objet.vin)}`, href: `/objet/${objet.vehicule_id}` });
  }

  if (r.prefixe === 'ETB') {
    const fac = (await groupePar('ops_facture', 'etablissement_id')).get(objet.id) || [];
    const ouvertes = fac.filter((f) => f.statut !== 'soldee');
    liens.push({ outil: 6, texte: `<b>${nb(fac.length)}</b> factures · encours ouvert <b>${euro(somme(ouvertes, (f) => f.montant))}</b>`, href: '/outils/6-pilotage' });
    const cmt = (await table('ops_decision_comite')).filter((d) => d.etablissement_id === objet.id);
    liens.push({ outil: 9, texte: `<b>${nb(cmt.length)}</b> décisions de comité`, href: '/outils/9-comites' });
  }

  if (r.prefixe === 'ORD') {
    liens.push({ outil: 5, texte: `Clé de rapprochement sectorielle · véhicule ${echapper(objet.immatriculation)}`, href: `/objet/${objet.vehicule_id}` });
  }

  return liens;
}

// =========================================================== recherche

export async function pageRecherche(q) {
  const requete = q.trim().toUpperCase();
  const t0 = performance.now();
  const [clients, vehicules, etabs] = await Promise.all([table('ref_client'), table('ref_vehicule'), table('ref_etablissement')]);

  const resultats = [];
  const prefixe = requete.split('-')[0];
  if (RESOLVEURS.some((r) => r.prefixe === prefixe)) resultats.push({ id: requete, type: 'Identifiant direct', libelle: 'Ouvrir la fiche de cet objet', href: `/objet/${requete}` });
  for (const c of clients) if (c.nom.toUpperCase().includes(requete) || c.code_balance.includes(requete)) resultats.push({ id: c.id, type: `Compte client ${c.type}`, libelle: c.nom, href: `/objet/${c.id}` });
  for (const v of vehicules) if (v.vin.includes(requete) || v.immatriculation.toUpperCase().includes(requete)) resultats.push({ id: v.immatriculation, type: 'Véhicule', libelle: `${v.modele} · ${v.vin}`, href: `/objet/${v.id}` });
  for (const e of etabs) if (e.nom.toUpperCase().includes(requete)) resultats.push({ id: e.id, type: 'Établissement', libelle: e.nom, href: `/objet/${e.id}` });
  const ms = performance.now() - t0;

  return el(`<div>
    <div class="fil"><a href="/">Accueil</a> › Recherche</div>
    <h1>Recherche transverse</h1>
    <p style="color:var(--texte-2)"><b>${nb(resultats.length)}</b> résultat(s) pour
      <code>${echapper(q)}</code> · <span class="mesure">${ms.toFixed(0)} ms sur ${nb(clients.length + vehicules.length + etabs.length)} objets, mesuré sur cet appareil</span></p>
    ${resultats.length ? `<div class="carte" style="margin-top:1rem"><table class="tab">
      <thead><tr><th>Identifiant</th><th>Nature</th><th>Libellé</th><th></th></tr></thead>
      <tbody>${resultats.slice(0, 120).map((r) => `<tr>
        <td class="id">${echapper(r.id)}</td><td>${echapper(r.type)}</td>
        <td>${echapper(r.libelle)}</td><td><a href="${r.href}">ouvrir</a></td></tr>`).join('')}</tbody>
    </table></div>` : `<div class="note" style="margin-top:1rem">Aucun résultat. Essayez un identifiant
      comme <code>TX-000874</code>, une immatriculation, un numéro de série ou un nom de client.</div>`}
  </div>`);
}

// =========================================================== visite guidee

const VISITE = [
  { t: "Un virement arrive", d: "Un virement global de plus de cent mille euros, libellé tronqué par la banque, aucune référence exploitable. Personne ne sait à qui l'imputer.", o: null },
  { t: "L'outil 4 identifie le payeur", d: "Il croise l'IBAN déjà observé, la référence de bout en bout, et cherche un sous-ensemble de factures dont la somme tombe juste. Il rend un score et ses preuves.", o: '4-affectation' },
  { t: "L'outil 5 lettre les écritures", d: "Les factures se rapprochent par leurs numéros de série. Une ligne refuse de se lettrer : le numéro de série contredit le reste.", o: '5-lettrage' },
  { t: "L'outil 6 voit l'encours baisser", d: "Le tableau de bord se recalcule. On descend du groupe jusqu'à l'écriture. Une facture reste ouverte.", o: '6-pilotage' },
  { t: "L'outil 7 dit pourquoi elle reste ouverte", d: "Il manque une pièce, et la grille de ce payeur-là l'exige. Le montant bloqué est affiché.", o: '7-grands-comptes' },
  { t: "L'outil 8 bloque un décaissement", d: "Le même client demande un remboursement supérieur à son engagement de reprise contractuel. Aucun fichier de paiement n'est produit.", o: '8-remboursements' },
  { t: "L'outil 9 porte le dossier en comité", d: "La décision devient une donnée : motif, responsable, échéance. Elle rejoint les milliers d'autres et devient mesurable.", o: '9-comites' },
  { t: "La pièce est déposée", d: "Le dossier se complète. Rien n'a été relancé pendant ce temps.", o: null },
  { t: "L'outil 10 retire la facture du portefeuille", d: "Elle sort de la relance, automatiquement, parce qu'une régularisation est en cours. Le compteur de relances utiles baisse d'une unité.", o: '10-relance' },
  { t: "L'outil 3 remesure la maturité", d: "Deux axes ont bougé. Trois n'ont pas bougé, parce qu'un outil ne les change pas.", o: '3-maturite' },
];

export async function pageVisite(etape) {
  const i = Math.min(Math.max(etape, 1), VISITE.length);
  const e = VISITE[i - 1];
  return el(`<div>
    <div class="fil"><a href="/">Accueil</a> › Visite guidée</div>
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap">
      <h1>Visite guidée</h1>
      <span class="mesure">étape ${i} sur ${VISITE.length} · environ ${Math.max(1, Math.round((VISITE.length - i + 1) * 0.5))} min restantes</span>
    </div>
    <div class="barre__p" style="margin:.8rem 0 1.2rem"><i style="width:${(i / VISITE.length) * 100}%"></i></div>
    <div class="carte">
      <h2>${echapper(e.t)}</h2>
      <p style="margin-top:.6rem;font-size:1.02rem;color:var(--texte-2)">${echapper(e.d)}</p>
      <div style="display:flex;gap:.6rem;margin-top:1.2rem;flex-wrap:wrap">
        ${i > 1 ? `<a class="btn" href="/visite/${i - 1}">Précédent</a>` : ''}
        ${i < VISITE.length ? `<a class="btn btn--prim" href="/visite/${i + 1}">Suivant</a>` : `<a class="btn btn--prim" href="/resultats">Voir les résultats</a>`}
        ${e.o ? `<a class="btn" href="/outils/${e.o}">Ouvrir le dossier</a>` : ''}
      </div>
    </div>
    <p class="note" style="margin-top:1rem">Vous pouvez sortir de la visite à tout moment et
      <a href="/">explorer librement</a> les dix outils.</p>
  </div>`);
}

// =========================================================== avant / apres

const AVANT_APRES = [
  ['Donnée dispersée', 'Données croisées', '/outils/4-affectation'],
  ['Rapprochement manuel', 'Décision expliquée', '/outils/5-lettrage'],
  ['Contrôles périodiques', 'Contrôles au passage du dossier', '/outils/8-remboursements'],
  ['Dossiers documentaires séparés', 'Pièces structurées par exigence de payeur', '/outils/7-grands-comptes'],
  ["Relance fondée sur l'ancienneté", 'Relance fondée sur la réalité de la créance', '/outils/10-relance'],
  ["Encours lu d'une seule façon", 'Deux lectures et leur écart', '/outils/6-pilotage'],
  ['Décision non tracée', 'Chaîne de décisions vérifiable', '/outils/9-comites'],
  ["Le chiffre, sans son origine", 'Tout chiffre remonte à sa ligne', '/resultats'],
];

export async function pageAvantApres() {
  return el(`<div>
    <div class="fil"><a href="/">Accueil</a> › Avant / après</div>
    <h1>Ce que la suite change</h1>
    <p style="color:var(--texte-2);max-width:66ch">Huit changements. Chaque ligne renvoie à
      l'écran qui la prouve : une affirmation qu'on ne peut pas vérifier n'est pas une preuve.</p>
    <div class="carte" style="margin-top:1.1rem"><table class="tab">
      <thead><tr><th style="width:44%">Avant</th><th style="width:44%">Après</th><th></th></tr></thead>
      <tbody>${AVANT_APRES.map(([a, b, h]) => `<tr>
        <td style="color:var(--texte-2)">${echapper(a)}</td>
        <td><b>${echapper(b)}</b></td>
        <td><a href="${h}">voir</a></td></tr>`).join('')}</tbody>
    </table></div>
  </div>`);
}

// =========================================================== pour le confrere

export async function pageTransposer() {
  return el(`<div>
    <div class="fil"><a href="/">Accueil</a> › Pour le confrère</div>
    <h1>Transposer la méthode chez un autre client</h1>
    <p style="color:var(--texte-2);max-width:70ch">Le noyau se transpose tel quel chez n'importe
      quel client. Le secteur n'apporte que des clés supplémentaires, et c'est cela qui fait la valeur.</p>

    <div class="grille g2" style="margin-top:1.2rem">
      <div class="carte">
        <div class="carte__titre"><h2>Noyau générique</h2><small>tout cabinet</small></div>
        <p style="color:var(--texte-2);font-size:.87rem">Les colonnes qu'un fichier d'écritures et
          un relevé bancaire portent toujours. Rien d'autre n'est nécessaire pour démarrer.</p>
        <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.6rem">
          ${TRANSPOSITION.generique.map((c) => `<span class="past past--accent">${echapper(c)}</span>`).join('')}
        </div>
      </div>
      <div class="carte">
        <div class="carte__titre"><h2>Clés sectorielles</h2><small>distribution automobile</small></div>
        <p style="color:var(--texte-2);font-size:.87rem">Ce que le secteur ajoute, et ce qu'aucun
          outil de place ne sait exploiter.</p>
        <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.6rem">
          ${TRANSPOSITION.sectoriel.map((c) => `<span class="past past--ambre">${echapper(c)}</span>`).join('')}
        </div>
      </div>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Les règles, déclarées une par une</h2>
        <small>${REGLES.length} règles, chacune avec sa référence</small></div>
      <p style="color:var(--texte-2);font-size:.87rem">Ces règles sont des <b>données</b>, pas du code
        enfoui : elles se paramètrent sans écrire une ligne. Chaque référence désigne l'origine
        fonctionnelle de la règle dans les développements de la mission.</p>
      <div class="tab__cadre" style="margin-top:.7rem"><table class="tab">
        <thead><tr><th>Réf.</th><th>Outil</th><th>Règle</th><th>Énoncé</th></tr></thead>
        <tbody>${REGLES.map((r) => `<tr>
          <td class="id">${r.ref}</td><td class="num">${r.outil}</td>
          <td><b>${echapper(r.nom)}</b><br><small style="color:var(--texte-3)">${echapper(r.origine)}</small></td>
          <td>${echapper(r.enonce)}</td></tr>`).join('')}</tbody>
      </table></div>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Ce que la méthode ne fait pas</h2></div>
      <p>Elle ne remplace aucun jugement professionnel. Elle ne décide pas d'une perte, elle
        l'instruit. Elle ne juge pas la solvabilité d'un client, elle établit que la question ne
        se pose que dans une minorité de cas. Et elle refuse de trancher chaque fois que deux
        hypothèses se tiennent : <b>un taux d'automatisation de cent pour cent serait un défaut
        de conception</b>, pas une performance.</p>
    </div>
  </div>`);
}

// =========================================================== methode

export async function pageMethode() {
  const info = await manifest();
  return el(`<div>
    <div class="fil"><a href="/">Accueil</a> › Méthode</div>
    <h1>Données, contrôles et limites</h1>

    <div class="carte">
      <div class="carte__titre"><h2>Les données sont entièrement fictives</h2></div>
      <p>Les données présentées sont fictives et ont été construites pour reproduire les mécanismes
        métier rencontrés dans les groupes automobiles multimarques et multi-concessions,
        <b>sans exposer aucune donnée client</b>. Les mécanismes des outils proviennent en revanche
        de développements réels.</p>
      ${paires([
        ['Groupe', info.groupe],
        ['Graine du générateur', String(info.graine)],
        ['Période couverte', `${date(info.periode[0])} au ${date(info.periode[1])}`],
        ['Lignes fabriquées', nb(Object.values(info.tables).reduce((s, x) => s + x, 0))],
        ['Tables', String(Object.keys(info.tables).length)],
      ])}
      <p class="note note--info" style="margin-top:.9rem">Le générateur est <b>déterministe</b> :
        à graine égale, l'univers est reconstruit à l'identique. Un chiffre cité reste donc
        vérifiable dans le temps.</p>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Les formats sont valides, les valeurs ne désignent personne</h2></div>
      <p>Les numéros de série respectent la norme et portent une clé de contrôle juste, sur un
        identifiant constructeur composé pour n'appartenir à aucun constructeur. Les IBAN ont une
        clé modulo 97 juste, sur un code banque non attribué. Les immatriculations respectent le
        format en vigueur, sur des séries non émises. <b>Le format résiste à l'inspection, la
        valeur ne renvoie à personne.</b></p>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Aucun indicateur n'est écrit en dur</h2></div>
      <p>Chaque chiffre affiché est recalculé au moment où l'écran s'affiche, à partir des lignes.
        Cliquez n'importe quel indicateur : vous verrez sa formule, la durée réellement mesurée
        sur votre appareil, et les lignes qui le composent. <b>Les durées affichées sont
        chronométrées, jamais annoncées.</b></p>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Rien n'est enregistré, rien ne se dégrade</h2></div>
      <p>Les données livrées sont immuables. Vos actions écrivent dans une couche de session
        privée à votre onglet, effacée à sa fermeture. Deux visiteurs ne se voient pas, et
        <b>il est impossible d'abîmer la démonstration pour quelqu'un d'autre</b>. Le bouton
        ↻ Réinitialiser vide cette couche en un clic.</p>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Cette plateforme ne dépend de rien</h2></div>
      <p>Elle n'appelle aucune base, aucune interface de programmation, aucun service tiers, aucun
        système d'un groupe. Elle ne charge aucune police, aucune image et aucun script distant.
        Elle fonctionne réseau coupé. <b>Le lien d'accès n'est pas une mesure de sécurité</b> :
        il évite qu'un visiteur arrive ici par hasard, et il n'a rien à protéger puisque la
        plateforme ne contient aucune donnée réelle.</p>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Filiation</h2></div>
      <p>Les mécanismes présentés sont repris de développements opérationnels conduits pendant la
        mission. Ils sont ici <b>réimplémentés</b>, non recopiés : la plateforme est un produit de
        démonstration autonome, sans lien technique avec aucun système de production. Chaque règle
        porte une référence et le libellé fonctionnel de son origine, visibles sur la page
        <a href="/transposer">Pour le confrère</a>.</p>
    </div>
  </div>`);
}
