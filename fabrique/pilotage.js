// Fabrique de l'outil 6 — le cockpit de pilotage. GROUPE SYNTHAUTO.
//
// ADDITIVE, comme celle du lettrage, et pour la meme raison : les outils 4 et 5
// sont figes, leurs tests finaux sont publies, et leurs mondes ne doivent pas
// bouger d'une ligne. Ce module n'ecrit que dans des tables NOUVELLES et ne
// modifie aucune ligne existante -- ni facture, ni ecriture, ni virement.
//
// Il apporte les deux choses qui manquent pour piloter :
//
//   1. le CHIFFRE D'AFFAIRES par societe, etablissement, cycle et mois. Sans
//      lui, aucun DSO n'est calculable : on ne peut que le simuler, et un DSO
//      simule ne se defend pas.
//   2. la CAUSE d'ouverture de chaque creance encore ouverte. C'est elle qui
//      permet de repondre a « pourquoi cette facture reste-t-elle ouverte »,
//      et qui prepare les outils 7, 9 et 10.
//
// Ce module n'ecrit JAMAIS la verite : voir fabrique/verite.js.

import { generateur, GRAINE_O6 } from './aleatoire.js';

/**
 * Les cinq causes pour lesquelles une creance reste ouverte, et ce qu'elles
 * appellent. La repartition est un choix de conception, pas une extrapolation
 * d'un chiffre reel.
 */
const CAUSES = [
  // Reellement due : le client doit, et il faut le relancer. -> outil 10
  ['reellement_due', 0.46],
  // Une piece manque au dossier du loueur ou du financeur. -> outil 7
  ['piece_manquante', 0.19],
  // Le comite de concession a decide quelque chose. -> outil 9
  ['decision_concession', 0.11],
  // Un financeur paie a la place du client : le payeur n'est pas le facture.
  ['financeur', 0.14],
  // Exception comptable : ecart, avoir en attente, imputation a revoir.
  ['exception_comptable', 0.10],
];

/** Les cycles d'activite d'une concession, et leur poids dans le chiffre d'affaires. */
const CYCLES = [
  ['VN', 0.52], // vehicules neufs : gros montants, delais courts
  ['VO', 0.21], // occasion
  ['APV', 0.19], // apres-vente : petits montants, nombreux
  ['CARR', 0.08], // carrosserie : delais longs, assureurs
];

/**
 * Delai de reglement moyen par cycle, en jours. Il donne au DSO synthetique une
 * dispersion sectoriellement credible : un vehicule neuf se paie au comptant ou
 * par un financeur qui regle vite, une carrosserie attend l'assureur.
 */
const DELAI_CYCLE = { VN: 12, VO: 21, APV: 38, CARR: 74 };

/**
 * Construit les tables du cockpit.
 *
 * @param {object} m monde deja construit
 */
export function planterPilotage(m) {
  const rnd = generateur(GRAINE_O6);

  const etabParId = new Map(m.ref_etablissement.map((e) => [e.id, e]));
  const cent = (x) => Math.round(x * 100);
  const eur = (c) => c / 100;

  // ------------------------------------------------- 1. le chiffre d'affaires
  //
  // On ne l'invente pas a partir de rien : on le derive du portefeuille de
  // factures reellement emises, par societe, etablissement, cycle et mois. Le
  // denominateur du DSO est ainsi coherent avec son numerateur -- c'est la
  // premiere chose qu'un examinateur verifie sur un DSO.
  const parCle = new Map();
  for (const f of m.ops_facture) {
    const etab = etabParId.get(f.etablissement_id);
    if (!etab) continue;
    const mois = String(f.date).slice(0, 7);
    const cycle = f.type === 'GAR' ? 'APV' : (f.type || 'VN');
    const cle = `${etab.societe_id}|${etab.id}|${cycle}|${mois}`;
    if (!parCle.has(cle)) {
      parCle.set(cle, {
        societe_id: etab.societe_id, etablissement_id: etab.id,
        cycle, mois, facture_ht: 0, nb_factures: 0,
      });
    }
    const l = parCle.get(cle);
    // Le chiffre d'affaires est hors taxes : les factures sont toutes taxes
    // comprises, comme les creances clients. Le rapport est de 1,2.
    l.facture_ht += Math.round(cent(f.montant) / 1.2);
    l.nb_factures += 1;
  }

  m.ops_chiffre_affaires = [...parCle.values()]
    .map((l) => ({
      societe_id: l.societe_id,
      etablissement_id: l.etablissement_id,
      cycle: l.cycle,
      mois: l.mois,
      chiffre_affaires_ht: eur(l.facture_ht),
      chiffre_affaires_ttc: eur(Math.round(l.facture_ht * 1.2)),
      nb_factures: l.nb_factures,
      delai_moyen_theorique: DELAI_CYCLE[l.cycle] ?? 30,
    }))
    .sort((a, b) => (a.mois + a.etablissement_id).localeCompare(b.mois + b.etablissement_id));

  // ------------------------------------------------- 2. la cause d'ouverture
  //
  // Une facture encore ouverte apres affectation et lettrage n'est pas
  // forcement due. Cinq situations distinctes, cinq suites differentes, et le
  // cockpit doit savoir les nommer.
  const ouvertes = m.ops_facture.filter((f) => f.statut !== 'soldee');
  m.ops_cause_ouverture = ouvertes.map((f) => {
    let cause = rnd.poids(CAUSES);
    // Une facture de garantie constructeur qui traine releve presque toujours
    // d'une piece manquante au dossier : c'est le fait sectoriel.
    if (f.type === 'GAR' && rnd.chance(0.62)) cause = 'piece_manquante';
    return {
      facture_id: f.id,
      client_id: f.client_id,
      societe_id: f.societe_id,
      etablissement_id: f.etablissement_id,
      cause,
      // L'outil qui prend la suite. Il n'est pas toujours construit, et le
      // cockpit le dit plutot que de promettre un lien mort.
      suite: {
        reellement_due: 'outil-10',
        piece_manquante: 'outil-7',
        decision_concession: 'outil-9',
        financeur: 'outil-4',
        exception_comptable: 'comptable',
      }[cause],
      montant: f.montant,
      date_facture: f.date,
      echeance: f.echeance,
    };
  });

  // La liste des cycles reste declaree : le cockpit filtre dessus.
  m.ref_cycle = CYCLES.map(([code, poids]) => ({
    code,
    libelle: { VN: 'Véhicules neufs', VO: 'Occasion', APV: 'Après-vente', CARR: 'Carrosserie' }[code],
    poids_cible: poids,
    delai_moyen_theorique: DELAI_CYCLE[code],
  }));

  return {
    lignes_ca: m.ops_chiffre_affaires.length,
    causes: m.ops_cause_ouverture.length,
  };
}
