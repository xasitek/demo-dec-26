// Moteur d'indicateurs.
//
// REGLE ABSOLUE : aucun indicateur n'est ecrit en dur. Chaque indicateur est
// declare une fois avec sa FORMULE et ses LIGNES SOURCES, puis calcule a
// l'affichage. Un clic sur une carte ouvre la formule, puis les lignes.
//
// Deux controles rendent la regle executoire, voir garde/controles.js :
//   - aucune valeur numerique litterale dans un emplacement d'indicateur ;
//   - sur un jeu ampute de 10 % des lignes, TOUS les compteurs doivent bouger.

import { table } from './donnees.js';

const registre = new Map();

/**
 * @param {object} d
 * @param {string} d.id           identifiant stable, cite dans le rapport
 * @param {string} d.libelle      ce que le chiffre dit
 * @param {string} d.formule      la formule, affichee telle quelle au visiteur
 * @param {string[]} d.sources    tables lues
 * @param {'nombre'|'euro'|'pourcent'|'jour'} d.unite
 * @param {(ctx:object)=>Promise<{valeur:number, lignes?:any[]}>} d.calcul
 */
export function declarer(d) {
  if (registre.has(d.id)) throw new Error(`Indicateur deja declare : ${d.id}`);
  registre.set(d.id, d);
  return d;
}

export const indicateur = (id) => registre.get(id);
export const tousIndicateurs = () => [...registre.values()];

const resultats = new Map();

/** Calcule un indicateur et chronometre reellement son execution. */
export async function calculer(id, ctx = {}) {
  const d = registre.get(id);
  if (!d) throw new Error(`Indicateur inconnu : ${id}`);
  const cle = `${id}|${JSON.stringify(ctx)}`;
  if (resultats.has(cle)) return resultats.get(cle);
  const t0 = performance.now();
  const sortie = await d.calcul({ table, ...ctx });
  const r = { ...d, ...sortie, ms: performance.now() - t0 };
  resultats.set(cle, r);
  return r;
}

export function viderCache() { resultats.clear(); }

// --------------------------------------------------------------- agregats

export const somme = (l, f) => l.reduce((s, x) => s + (f ? f(x) : x), 0);
export const compte = (l, f) => (f ? l.filter(f).length : l.length);
export const part = (n, d) => (d ? n / d : 0);

export function repartir(lignes, cle, valeur = () => 1) {
  const m = new Map();
  for (const l of lignes) {
    const k = typeof cle === 'function' ? cle(l) : l[cle];
    m.set(k, (m.get(k) || 0) + valeur(l));
  }
  return [...m].map(([k, v]) => ({ cle: k, valeur: v })).sort((a, b) => b.valeur - a.valeur);
}

// ------------------------------------------------- indicateurs du socle
// Ils portent sur les lignes elles-memes : ils sont donc disponibles avant
// tout branchement de moteur, et ils prouvent que la mecanique fonctionne.

declarer({
  id: 'SOC-ECRITURES', libelle: 'Écritures comptables dans le périmètre',
  formule: 'compte(ops_ecriture)', sources: ['ops_ecriture'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ops_ecriture'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-FACTURES', libelle: 'Factures émises sur la période',
  formule: 'compte(ops_facture)', sources: ['ops_facture'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ops_facture'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-ENCOURS', libelle: 'Encours client ouvert',
  formule: "somme(montant) sur ops_facture ou statut != 'soldee'",
  sources: ['ops_facture'], unite: 'euro',
  async calcul({ table: t }) {
    const l = (await t('ops_facture')).filter((f) => f.statut !== 'soldee');
    return { valeur: somme(l, (f) => f.montant), lignes: l };
  },
});

declarer({
  id: 'SOC-NON-LETTRE', libelle: 'Écritures non lettrées',
  formule: 'compte(ops_ecriture ou lettrage est vide)', sources: ['ops_ecriture'], unite: 'nombre',
  async calcul({ table: t }) {
    const l = (await t('ops_ecriture')).filter((e) => !e.lettrage);
    return { valeur: l.length, lignes: l };
  },
});

declarer({
  id: 'SOC-MONTANT-NON-LETTRE', libelle: 'Montant porté par les écritures non lettrées',
  formule: 'somme(montant) sur ops_ecriture ou lettrage est vide',
  sources: ['ops_ecriture'], unite: 'euro',
  async calcul({ table: t }) {
    const l = (await t('ops_ecriture')).filter((e) => !e.lettrage);
    return { valeur: somme(l, (e) => e.montant), lignes: l };
  },
});

declarer({
  id: 'SOC-VIREMENTS', libelle: 'Virements reçus à identifier',
  formule: 'compte(ops_virement)', sources: ['ops_virement'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ops_virement'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-VIREMENTS-MONTANT', libelle: 'Montant des virements reçus',
  formule: 'somme(montant) sur ops_virement', sources: ['ops_virement'], unite: 'euro',
  async calcul({ table: t }) { const l = await t('ops_virement'); return { valeur: somme(l, (v) => v.montant), lignes: l }; },
});

declarer({
  id: 'SOC-SANS-REFERENCE', libelle: 'Virements sans référence exploitable',
  formule: 'compte(ops_virement ou reference_bout_en_bout est vide)',
  sources: ['ops_virement'], unite: 'nombre',
  async calcul({ table: t }) {
    const l = (await t('ops_virement')).filter((v) => !v.reference_bout_en_bout);
    return { valeur: l.length, lignes: l };
  },
});

declarer({
  id: 'SOC-CODES-DIVERGENTS', libelle: 'Comptes clients dont le code diverge entre référentiel et balance',
  formule: 'compte(ref_client ou code_referentiel != code_balance)',
  sources: ['ref_client'], unite: 'nombre',
  async calcul({ table: t }) {
    const l = (await t('ref_client')).filter((c) => c.code_referentiel !== c.code_balance);
    return { valeur: l.length, lignes: l };
  },
});

declarer({
  id: 'SOC-REMBOURSEMENTS', libelle: 'Demandes de remboursement déposées',
  formule: 'compte(ops_dossier_remboursement)', sources: ['ops_dossier_remboursement'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ops_dossier_remboursement'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-DOSSIERS-LIVRAISON', libelle: 'Dossiers de livraison grands comptes',
  formule: 'compte(ops_dossier_livraison)', sources: ['ops_dossier_livraison'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ops_dossier_livraison'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-COMITES', libelle: 'Décisions de comité de créances',
  formule: 'compte(ops_decision_comite)', sources: ['ops_decision_comite'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ops_decision_comite'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-COMITES-SOLVABILITE',
  libelle: "Part des montants de comité qui met en cause la solvabilité du client",
  formule: "somme(montant) ou motif dans {Facture en litige, Litige client} / somme(montant)",
  sources: ['ops_decision_comite'], unite: 'pourcent',
  async calcul({ table: t }) {
    const l = await t('ops_decision_comite');
    const litige = l.filter((d) => d.motif === 'Facture en litige' || d.motif === 'Litige client');
    return { valeur: part(somme(litige, (d) => d.montant), somme(l, (d) => d.montant)), lignes: litige };
  },
});

declarer({
  id: 'SOC-RELANCE-PORTEFEUILLE', libelle: 'Lignes du portefeuille de relance',
  formule: 'compte(ops_ligne_relance)', sources: ['ops_ligne_relance'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ops_ligne_relance'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-VEHICULES', libelle: 'Véhicules suivis',
  formule: 'compte(ref_vehicule)', sources: ['ref_vehicule'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ref_vehicule'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-CLIENTS', libelle: 'Comptes clients',
  formule: 'compte(ref_client)', sources: ['ref_client'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ref_client'); return { valeur: l.length, lignes: l }; },
});

declarer({
  id: 'SOC-BUYBACK', libelle: 'Contrats d\'engagement de reprise au référentiel',
  formule: 'compte(ref_contrat_buyback)', sources: ['ref_contrat_buyback'], unite: 'nombre',
  async calcul({ table: t }) { const l = await t('ref_contrat_buyback'); return { valeur: l.length, lignes: l }; },
});
