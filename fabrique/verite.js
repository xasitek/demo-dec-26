// SEUL module qui produit la verite de reference.
// Il ecrit dans un fichier de donnees SEPARE, que les moteurs n'ouvrent jamais.
// Regle : aucune colonne de verite ne porte le meme nom qu'une colonne du monde.

import { ControleBuyBack } from './regles-partagees.js';

export function construireVerite(m) {
  const v = { meta: { graine: m.meta.graine, genere_le: m.meta.genere_le, avertissement: 'Verite de reference. Ne doit jamais etre lue par un moteur de decision.' } };

  const parId = (t) => Object.fromEntries(t.map((x) => [x.id, x]));
  const iban = parId(m.ref_iban);
  const client = parId(m.ref_client);
  const loueur = parId(m.ref_loueur);
  const contrat = parId(m.ref_contrat_buyback);

  // --- virements : quel est le vrai payeur, et le moteur devait-il trancher ?
  //
  // Le vrai payeur est celui que la fabrique a CHOISI, pas celui qu'on
  // reconstituerait apres coup a partir de l'empreinte d'IBAN. La nuance a
  // coute cher : tant que la verite se reconstruisait, un compte partage entre
  // deux codes clients faisait compter comme faux un rapprochement juste.
  //
  // Deux situations seulement appellent une main humaine, et toutes deux sont
  // construites dans le monde, pas decretees ici :
  //   SC-04-10  deux comptes clients, un seul compte bancaire, meme raison
  //             sociale, une facture ouverte du meme montant de chaque cote,
  //             et rien dans le libelle pour departager ;
  //   SC-04-11  un montant qu'aucun sous-ensemble de factures ne compose.
  v.truth_virement = m.ops_virement.map((t) => {
    const sc = m._scenarios.get(t.id);
    // Le drapeau vient de la fabrique, qui SAIT si le piege a ete arme. La
    // verite ne le deduit plus du nom du scenario : c'est precisement ce
    // raccourci qui faisait compter comme fausses des affectations justes.
    const humain = m._ambigus.get(t.id) === true;

    return {
      objet_id: t.id,
      vrai_client: m._payeurs.get(t.id) || null,
      vraie_societe: t.societe_id,
      decision_attendue: humain ? 'exception' : 'auto',
      code_scenario: sc,
    };
  });

  // --- reglements : quelles factures composent reellement chaque virement
  v.truth_reglement = m.ops_virement.map((t) => ({
    objet_id: t.id,
    factures_de_reference: (m._compositions.get(t.id) || []).join('|'),
    nb_factures: (m._compositions.get(t.id) || []).length,
  }));

  // --- dossiers de livraison : la vraie cause de blocage
  v.truth_livraison = m.ops_dossier_livraison.map((d) => {
    const pieces = m.ops_piece_livraison.filter((p) => p.dossier_id === d.id);
    const manquante = pieces.find((p) => !p.presente);
    const illisible = pieces.find((p) => p.presente && !p.lisible);
    return {
      objet_id: d.id,
      cause_blocage: manquante ? `piece_absente:${manquante.type}`
        : illisible ? `piece_illisible:${illisible.type}` : null,
      decision_attendue: m._scenarios.get(d.id) === 'SC-07-06' ? 'sort_du_dossier'
        : manquante ? 'bloque' : illisible ? 'complement' : 'payable',
      exigence_du_payeur: loueur[d.loueur_id].grille.join('|'),
      code_scenario: m._scenarios.get(d.id),
    };
  });

  // --- remboursements : la decision attendue et le controle qui doit la declencher
  const ATTENDU = {
    'SC-08-01': ['paye', null], 'SC-08-02': ['alerte', 'C04-iban-forme'],
    'SC-08-03': ['bloque', 'C10-empreinte-iban'], 'SC-08-04': ['refuse', 'C09-unicite-base'],
    'SC-08-05': ['alerte', 'C11-jumeaux'], 'SC-08-06': ['paye', null],
    'SC-08-07': ['bloque', 'C07-surpaiement-buyback'], 'SC-08-08': ['paye', null],
    'SC-08-09': ['refuse', 'C26-role-perimetre'], 'SC-08-10': ['refuse', 'C28-garde-paiement'],
    'SC-08-11': ['arbitrage', 'C18-montant-vs-facture'], 'SC-08-12': ['alerte', 'C23-iban-modifie'],
    'SC-08-13': ['alerte', 'C14-mention-manuscrite'], 'SC-08-14': ['bloque', 'C15-vehicule-gage'],
    'SC-08-15': ['alerte', 'C16-estimation-retouchee'], 'SC-08-16': ['humain', 'C13-piece-inexploitable'],
    'SC-08-17': ['paye', 'C34-identifiant-message'], 'SC-08-18': ['paye', 'C33-unicite-fichier'],
    'SC-08-19': ['oriente', 'C25-cas-financement'], 'SC-08-20': ['humain', null],
  };
  v.truth_remboursement = m.ops_dossier_remboursement.map((d) => {
    const [decision, controle] = ATTENDU[m._scenarios.get(d.id)];
    const c = d.contrat_buyback_id ? contrat[d.contrat_buyback_id] : null;
    const ecart = c ? Math.round((d.montant_demande - c.er_ttc) * 100) / 100 : null;
    return {
      objet_id: d.id, decision_attendue: decision, controle_declencheur: controle,
      ecart_contractuel: ecart,
      surpaiement_reel: c ? ecart > ControleBuyBack.TOLERANCE : false,
      code_scenario: m._scenarios.get(d.id),
    };
  });

  // --- relance : faut-il vraiment contacter ce client ?
  v.truth_relance = m.ops_ligne_relance.map((l) => ({
    objet_id: l.id,
    doit_etre_contacte: ['SC-10-01', 'SC-10-08'].includes(m._scenarios.get(l.id)),
    cause_exclusion: {
      'SC-10-01': null, 'SC-10-02': 'deja_payee_non_affectee', 'SC-10-03': 'piece_manquante',
      'SC-10-04': 'a_reclamer_au_financeur', 'SC-10-05': 'litige', 'SC-10-06': 'promesse_en_cours',
      'SC-10-07': 'regularisation_comite', 'SC-10-08': null,
    }[m._scenarios.get(l.id)],
    niveau_attendu: m._scenarios.get(l.id) === 'SC-10-08' ? Math.min(l.relances_deja_envoyees + 1, 3) : 1,
    code_scenario: m._scenarios.get(l.id),
  }));

  // --- quel scenario a produit chaque objet : c'est une reponse, elle vit ici
  v.truth_scenario = [...m._scenarios].map(([objetId, code]) => ({ objet_id: objetId, code_scenario: code }));

  // --- factures : le vrai statut, independamment de ce que la balance affiche
  v.truth_facture = m.ops_facture.map((f) => ({
    objet_id: f.id, statut_reel: f.statut,
  }));

  v.meta.tables = Object.keys(v).filter((k) => k !== 'meta');
  // --- lettrage : ce que chaque lot solde reellement, ou pourquoi il ne doit
  // pas etre soldé seul. Trois verdicts, et rien d'autre : TRUE_MATCH,
  // DOIT_RESTER_HUMAIN, AUCUN_MATCH_VALIDE.
  v.truth_lettrage = m._verites_lettrage || [];

  // --- lettrages deja poses : lesquels sont a revoir, et pour quel motif.
  v.truth_lettrage_historique = m._verites_lettrage_historique || [];

  // --- outil 7 : le verdict attendu de chaque dossier grand compte, et la
  // liste des anomalies reellement plantees. Deux tables, parce que ce sont
  // deux mesures : le verdict se juge dossier par dossier, la detection se
  // juge anomalie par anomalie.
  v.truth_dossier_gc = m._verites_grands_comptes || [];
  v.truth_anomalie_gc = m._verites_anomalies_gc || [];

  return v;
}
