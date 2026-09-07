// Point d'entree de la fabrique.
//   node fabrique/construire.js
// Ecrit public/donnees/monde/*.json et public/donnees/verite/*.json.
// N'ouvre AUCUNE source externe : ni fichier tiers, ni base, ni reseau.

import { mkdirSync, writeFileSync, rmSync, existsSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { construireMonde, VOLUMES } from './monde.js';
import { construireVerite } from './verite.js';
import { verifierContrats } from './contrats.js';
import { verifierContratsLettrage } from './contrats-lettrage.js';
import { verifierContratsGrandsComptes } from './contrats-grands-comptes.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..');
const MONDE = join(RACINE, 'public', 'donnees', 'monde');
const VERITE = join(RACINE, 'public', 'donnees', 'verite');

/**
 * Encodage colonnaire : une table devient un objet de colonnes.
 * Les colonnes a faible cardinalite sont encodees par dictionnaire.
 * Gain observe : de l'ordre de 60 % par rapport a une liste d'objets.
 */
function encoderTable(lignes) {
  if (!lignes.length) return { n: 0, cols: {} };
  // L'UNION des cles, pas celles de la premiere ligne. Le raccourci a coute
  // cher : un champ pose sur quelques lignes seulement -- le rattachement d'une
  // ecriture a un lot de lettrage -- disparaissait silencieusement de l'export,
  // et la colonne arrivait vide en base sans qu'aucune erreur ne soit levee.
  const noms = [...new Set(lignes.flatMap((l) => Object.keys(l)))];
  const cols = {};
  for (const nom of noms) {
    const brut = lignes.map((l) => l[nom]);
    const distincts = new Set(brut.map((x) => (Array.isArray(x) ? JSON.stringify(x) : x)));
    // Seuil large : un dictionnaire reste gagnant tant que la valeur est plus
    // longue que son index. Un numero de serie de 17 caracteres le reste jusque
    // tres haut en cardinalite.
    if (distincts.size <= brut.length * 0.75 && distincts.size <= 80000) {
      const dico = [...distincts];
      const index = new Map(dico.map((v, i) => [v, i]));
      cols[nom] = { d: dico.map((x) => (typeof x === 'string' && x.startsWith('[') ? JSON.parse(x) : x)),
        i: brut.map((x) => index.get(Array.isArray(x) ? JSON.stringify(x) : x)) };
    } else {
      cols[nom] = { v: brut };
    }
  }
  return { n: lignes.length, cols };
}

function ecrire(dossier, nom, lignes) {
  const fichier = join(dossier, `${nom}.json`);
  const texte = JSON.stringify(encoderTable(lignes));
  writeFileSync(fichier, texte);
  // Poids reellement transfere : les hebergeurs statiques compressent d'office.
  const comprime = gzipSync(Buffer.from(texte), { level: 9 }).length;
  return { nom, lignes: lignes.length, octets: statSync(fichier).size, comprime };
}

function taille(o) {
  return o < 1024 ? `${o} o` : o < 1048576 ? `${(o / 1024).toFixed(0)} Ko` : `${(o / 1048576).toFixed(1)} Mo`;
}

console.log('Fabrique de l\'univers GROUPE SYNTHAUTO');
const t0 = performance.now();

for (const d of [MONDE, VERITE]) { if (existsSync(d)) rmSync(d, { recursive: true }); mkdirSync(d, { recursive: true }); }

const monde = construireMonde();
const tMonde = performance.now();
const verite = construireVerite(monde);
const tVerite = performance.now();

// ---- Contrats de scenario. Ils passent AVANT l'ecriture : un monde qui ne
// materialise pas la difficulte annoncee par la verite ne sort pas de la
// fabrique. On ne mesure jamais un moteur sur un monde qui ment.
const controle = verifierContrats(monde, verite);
if (controle.manquements.length > 0) {
  console.error('');
  console.error('  CONTRATS DE SCENARIO NON TENUS');
  console.error('');
  for (const m of controle.manquements) {
    console.error(`  ${m.scenario} : ${m.fautes.length} manquement(s) sur ${m.verifies} verifies`);
    for (const f of m.fautes.slice(0, 4)) console.error(`      ${f}`);
    if (m.fautes.length > 4) console.error(`      ... et ${m.fautes.length - 4} autres`);
  }
  process.exit(1);
}
console.log('');
console.log(`  Contrats de scenario : ${Object.keys(controle.bilan).length} classes verifiees, aucun manquement.`);

// ---- Contrats du lettrage. Meme regle, meme sanction.
const controleLettrage = verifierContratsLettrage(monde, verite);
if (controleLettrage.manquements.length > 0) {
  console.error('');
  console.error('  CONTRATS DE LETTRAGE NON TENUS');
  console.error('');
  for (const m of controleLettrage.manquements) {
    console.error(`  ${m.scenario} : ${m.fautes.length} manquement(s) sur ${m.verifies} verifies`);
    for (const f of m.fautes.slice(0, 4)) console.error(`      ${f}`);
    if (m.fautes.length > 4) console.error(`      ... et ${m.fautes.length - 4} autres`);
  }
  process.exit(1);
}
console.log(`  Contrats de lettrage : ${Object.keys(controleLettrage.bilan).length} classes verifiees, aucun manquement.`);

// ---- Contrats des dossiers grands comptes. Meme regle, meme sanction : un
// scenario qui ne se materialise pas fait echouer la construction, parce qu'une
// verite qui mentirait rendrait toute mesure de l'outil 7 sans valeur.
const controleGc = verifierContratsGrandsComptes(monde, verite);
if (controleGc.total > 0) {
  console.error('');
  console.error('  CONTRATS DES DOSSIERS GRANDS COMPTES NON TENUS');
  console.error('');
  for (const m of controleGc.manquements) console.error(`      ${m}`);
  if (controleGc.total > controleGc.manquements.length) {
    console.error(`      ... et ${controleGc.total - controleGc.manquements.length} autres`);
  }
  process.exit(1);
}
console.log('  Contrats grands comptes : 17 scenarios et 6 invariants verifies, aucun manquement.');

const rapport = { monde: [], verite: [] };
for (const [nom, valeur] of Object.entries(monde)) {
  if (nom === 'meta') { writeFileSync(join(MONDE, 'meta.json'), JSON.stringify(valeur, null, 2)); continue; }
  // Les champs prefixes par _ sont des passages internes vers la fabrique de
  // verite : ils ne sont JAMAIS publies dans le paquet monde.
  if (nom.startsWith('_')) continue;
  rapport.monde.push(ecrire(MONDE, nom, valeur));
}
for (const [nom, valeur] of Object.entries(verite)) {
  if (nom === 'meta') { writeFileSync(join(VERITE, 'meta.json'), JSON.stringify(valeur, null, 2)); continue; }
  rapport.verite.push(ecrire(VERITE, nom, valeur));
}

// Index des tables, charge en premier par la plateforme.
const index = {
  graine: monde.meta.graine, groupe: monde.meta.groupe,
  periode: [monde.meta.debut, monde.meta.fin],
  tables: Object.fromEntries(rapport.monde.map((t) => [t.nom, t.lignes])),
};
writeFileSync(join(MONDE, 'index.json'), JSON.stringify(index, null, 2));
writeFileSync(join(VERITE, 'index.json'), JSON.stringify({
  tables: Object.fromEntries(rapport.verite.map((t) => [t.nom, t.lignes])),
  avertissement: verite.meta.avertissement,
}, null, 2));

// ---------------------------------------------------------------- vitrine
// Les « cas a decouvrir ». C'est de la METADONNEE D'AUTEUR, pas une entree de
// moteur : la fabrique, qui connait tout, designe les objets a montrer. Les
// moteurs n'y ont pas acces, et le libelle ne porte que des valeurs REELLEMENT
// lues sur l'objet, jamais un chiffre invente.
const parIdent = (t) => new Map(t.map((x) => [x.id, x]));
const sc = monde._scenarios;
const premierDe = (liste, code) => liste.find((x) => sc.get(x.id) === code);
const eur = (v) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(v)} €`;

const contratsIdx = parIdent(monde.ref_contrat_buyback);
const loueursIdx = parIdent(monde.ref_loueur);
const veriteRbc = new Map(verite.truth_remboursement.map((x) => [x.objet_id, x]));

const cas = (id, titre, sous) => (id ? { id, titre, sous } : null);
const vedette = monde.ops_virement.find((v) => v.vedette);
const rbcSur = premierDe(monde.ops_dossier_remboursement, 'SC-08-07');
const rbcTol = premierDe(monde.ops_dossier_remboursement, 'SC-08-06');
const dlvBat = premierDe(monde.ops_dossier_livraison, 'SC-07-02');
const dlvAutre = premierDe(monde.ops_dossier_livraison, 'SC-07-03');
const relPayee = premierDe(monde.ops_ligne_relance, 'SC-10-02');
const relTrois = premierDe(monde.ops_ligne_relance, 'SC-10-08');
const clientDivergent = monde.ref_client.find((c) => c.code_referentiel !== c.code_balance);

const vitrine = {
  '3-maturite': [
    cas(clientDivergent.id, 'Un compte, deux codes inconciliables',
      `${clientDivergent.code_referentiel} au référentiel, ${clientDivergent.code_balance} à la balance`),
  ].filter(Boolean),
  '4-affectation': [
    cas(vedette.id, `Virement global de ${eur(vedette.montant)}`, 'Six factures, trois sociétés, un seul virement'),
    cas(premierDe(monde.ops_virement, 'SC-04-04')?.id, 'Payeur différent du facturé', "Le nom du donneur d'ordre ne correspond à personne"),
    cas(premierDe(monde.ops_virement, 'SC-04-10')?.id, 'Deux candidats trop proches', "L'outil refusera de trancher seul"),
    cas(premierDe(monde.ops_virement, 'SC-04-11')?.id, 'Cas volontairement insoluble', 'Aucun sous-ensemble de factures ne compose ce montant'),
  ].filter(Boolean),
  '5-lettrage': [
    cas(premierDe(monde.ops_virement, 'SC-04-08')?.id, 'Somme exacte de plusieurs factures', 'Le rapprochement se joue sur la combinaison'),
    cas(premierDe(monde.ops_virement, 'SC-04-12')?.id, 'Mouvement bancaire de sens mixte', 'À découper avant tout rapprochement'),
  ].filter(Boolean),
  '7-grands-comptes': [
    dlvBat ? cas(dlvBat.id, 'Attestation de batterie absente',
      `${loueursIdx.get(dlvBat.loueur_id).nom} l'exige, ${eur(dlvBat.montant)} bloqués`) : null,
    dlvAutre ? cas(dlvAutre.id, 'Le même dossier chez un autre payeur',
      `${loueursIdx.get(dlvAutre.loueur_id).nom} n'a pas la même grille`) : null,
    cas(premierDe(monde.ops_dossier_livraison, 'SC-07-06')?.id, 'Facture réglée entre-temps', 'Elle sort du dossier par construction'),
  ].filter(Boolean),
  '8-remboursements': [
    rbcSur ? cas(rbcSur.id, "Surpaiement d'engagement de reprise",
      `Écart de ${eur(veriteRbc.get(rbcSur.id).ecart_contractuel)} pour une tolérance de 3 €`) : null,
    rbcTol ? cas(rbcTol.id, 'Écart de 2 € : le dossier passe', 'La tolérance contractuelle est respectée') : null,
    cas(premierDe(monde.ops_dossier_remboursement, 'SC-08-04')?.id, 'Doublon parfait', "Refusé à l'instant du dépôt"),
    cas(premierDe(monde.ops_dossier_remboursement, 'SC-08-03')?.id, 'Même compte bancaire, deux codes clients', "L'empreinte d'IBAN le voit"),
    cas(premierDe(monde.ops_dossier_remboursement, 'SC-08-09')?.id, 'Validation hors périmètre', "Le directeur n'est pas celui de cet établissement"),
    cas(premierDe(monde.ops_dossier_remboursement, 'SC-08-16')?.id, 'Panne de lecture des pièces', 'Ne devient jamais un refus'),
  ].filter(Boolean),
  '10-relance': [
    relPayee ? cas(relPayee.id, 'Déjà payée, jamais affectée', `${eur(relPayee.montant)} qu'il ne faut pas réclamer`) : null,
    relTrois ? cas(relTrois.id, 'Trois relances sans réponse', 'Niveau supérieur, pas une quatrième relance identique') : null,
  ].filter(Boolean),
};
writeFileSync(join(RACINE, 'public', 'donnees', 'vitrine.json'), JSON.stringify(vitrine, null, 1));
const nbCas = Object.values(vitrine).reduce((s, x) => s + x.length, 0);
console.log(`
  VITRINE : ${nbCas} cas a decouvrir, tous pointant sur un objet reel du jeu.`);

const octetsMonde = rapport.monde.reduce((s, t) => s + t.octets, 0);
const octetsVerite = rapport.verite.reduce((s, t) => s + t.octets, 0);
const zipMonde = rapport.monde.reduce((s, t) => s + t.comprime, 0);
const zipVerite = rapport.verite.reduce((s, t) => s + t.comprime, 0);

console.log('\n  MONDE');
for (const t of rapport.monde.sort((a, b) => b.octets - a.octets)) {
  console.log(`    ${t.nom.padEnd(28)} ${String(t.lignes).padStart(8)} lignes ${taille(t.octets).padStart(9)} ${taille(t.comprime).padStart(9)} transfere`);
}
console.log('\n  VERITE  (fichier separe, jamais lu par un moteur)');
for (const t of rapport.verite) {
  console.log(`    ${t.nom.padEnd(28)} ${String(t.lignes).padStart(8)} lignes ${taille(t.octets).padStart(9)} ${taille(t.comprime).padStart(9)} transfere`);
}
console.log(`
  sur disque  monde ${taille(octetsMonde)}  verite ${taille(octetsVerite)}  total ${taille(octetsMonde + octetsVerite)}`);
console.log(`  TRANSFERE   monde ${taille(zipMonde)}  verite ${taille(zipVerite)}  total ${taille(zipMonde + zipVerite)}`);
console.log(`  construction du monde  ${(tMonde - t0).toFixed(0)} ms`);
console.log(`  construction de la verite ${(tVerite - tMonde).toFixed(0)} ms`);
console.log(`  total ${(performance.now() - t0).toFixed(0)} ms`);

const attendus = Object.entries(VOLUMES);
const ecarts = attendus.filter(([k, n]) => {
  const table = { societes: 'ref_societe', concessions: 'ref_concession', etablissements: 'ref_etablissement',
    marques: 'ref_marque', banques: 'ref_banque', loueurs: 'ref_loueur', financeurs: 'ref_financeur',
    utilisateurs: 'ref_utilisateur', clients: 'ref_client', vehicules: 'ref_vehicule',
    buyback: 'ref_contrat_buyback', factures: 'ops_facture', ecritures: 'ops_ecriture',
    virements: 'ops_virement', ordresReparation: 'ops_ordre_reparation',
    dossiersLivraison: 'ops_dossier_livraison', dossiersRemboursement: 'ops_dossier_remboursement',
    decisionsComite: 'ops_decision_comite', lignesRelance: 'ops_ligne_relance' }[k];
  // Les virements se comptent en deux temps : la population du monde, plus la
  // seconde population aveugle tiree d'une autre graine.
  const cible = k === 'virements'
    ? n + VOLUMES.virements_blind2 + VOLUMES.virements_blind3 + VOLUMES.virements_blind4 : n;

  return table && index.tables[table] !== cible;
});
if (ecarts.length) { console.error('\n  ECART DE VOLUME :', ecarts); process.exit(1); }
console.log('\n  Volumes conformes a la cible.');
