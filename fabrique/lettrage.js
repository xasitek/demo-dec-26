// Fabrique de l'outil 5 — le lettrage. GROUPE SYNTHAUTO.
//
// Elle est ADDITIVE, et ce n'est pas un detail : l'outil 4 est fige, son test
// final est publie, et son monde ne doit pas bouger d'une ligne. Ce module
// n'ecrit donc que dans `ops_ecriture` et dans des tables nouvelles, jamais
// dans `ops_facture`, `ops_virement`, `ref_client`, `ref_iban`, `ref_vehicule`
// ni `ref_payeur_profil`. Il tire son alea d'une graine qui lui est propre, si
// bien que le flux aleatoire de l'outil 4 reste intact.
//
// Ce module n'ecrit JAMAIS la verite : voir fabrique/verite.js.

import {
  generateur, ajouterJours, GRAINE_O5, GRAINE_O5_BLIND, GRAINE_O5_BLIND_2, GRAINE_O5_BLIND_3,
} from './aleatoire.js';

/** Les scenarios de lettrage, et leur part. */
const SCEN_LET = [
  ['SC-05-01', 0.12], // montant exact sur cle forte
  ['SC-05-02', 0.07], // reference partagee, groupe transitif
  ['SC-05-03', 0.07], // numero de serie seul
  ['SC-05-04', 0.06], // immatriculation seule
  ['SC-05-05', 0.05], // ordre de reparation seul
  ['SC-05-06', 0.08], // sous-ensemble equilibre
  ['SC-05-07', 0.07], // convergence de cinq indices, aucune cle suffisante
  ['SC-05-08', 0.05], // meme montant, numero de serie contradictoire
  ['SC-05-09', 0.06], // ecart identique, anciennetes differentes
  ['SC-05-10', 0.05], // ressemblance forte, solde non conforme
  ['SC-05-11', 0.04], // deux sous-ensembles equilibrent
  ['SC-05-12', 0.06], // aucun rapprochement valide
  ['SC-05-14', 0.05], // inter-societe
  ['SC-05-15', 0.07], // garantie constructeur
  // Les classes qui donnent leur matiere aux methodes d'apurement. Sans elles,
  // neuf methodes du catalogue afficheraient zero sans qu'on sache si c'est
  // parce qu'elles echouent ou parce que le monde ne contient pas leur cas.
  ['SC-05-16', 0.02], // compte d'attente ancien, solde negligeable
  ['SC-05-17', 0.02], // acompte dormant
  ['SC-05-18', 0.02], // deux comptes clients a soldes opposes
  ['SC-05-19', 0.02], // ecritures de plus de deux ans
  ['SC-05-20', 0.02], // compte client ferme
  ['SC-05-21', 0.02], // liaison entre deux etablissements
  ['SC-05-22', 0.01], // nom de client unique
  ['SC-05-23', 0.02], // solde client nul
  ['SC-05-24', 0.02], // dossier comptant solde
];

/** Ce que la verite peut dire d'un lot. */
export const VERDICTS = {
  MATCH: 'TRUE_MATCH',
  HUMAIN: 'DOIT_RESTER_HUMAIN',
  AUCUN: 'AUCUN_MATCH_VALIDE',
};

/** Les cinq etats d'un lettrage deja pose que la relecture doit savoir juger. */
const ETATS_HISTORIQUES = [
  ['coherent', 0.52],
  ['contradictoire', 0.12],
  ['solde_incorrect', 0.11],
  ['mauvaise_cle', 0.13],
  ['doublon', 0.12],
];

/**
 * Plante les lots de lettrage dans le stock d'ecritures.
 *
 * @param {object} m monde deja construit
 * @returns {object} registres internes, destines a la seule fabrique de verite
 */
export function planterLettrage(m) {
  const rnd = generateur(GRAINE_O5);
  const rndBlind = generateur(GRAINE_O5_BLIND);

  const vehiculeParId = new Map(m.ref_vehicule.map((v) => [v.id, v]));
  const etabParId = new Map(m.ref_etablissement.map((e) => [e.id, e]));

  // Le stock disponible : les ecritures NON lettrees. C'est le vivier que le
  // moteur examinera, et c'est en son sein qu'on plante les cas.
  // Toutes les ecritures portent le champ, meme vide : une colonne qui
  // n'existe que sur quelques lignes est une colonne fragile.
  for (const e of m.ops_ecriture) { e.lot_demo = null; e.code_client_demo = null; }

  const libres = m.ops_ecriture.filter((e) => !e.lettrage);
  let curseur = 0;
  const prendre = (n) => {
    const lot = [];
    while (lot.length < n && curseur < libres.length) {
      const e = libres[curseur++];
      if (e._o5) continue;
      e._o5 = true;
      lot.push(e);
    }
    return lot;
  };

  const cent = (x) => Math.round(x * 100);
  const eur = (c) => c / 100;

  const lots = [];
  const verites = [];

  /** Pose un lot et sa verite. */
  const poser = (scenario, cohorte, ecritures, verdict, methode, notes) => {
    if (ecritures.length === 0) return null;
    const id = `LOT-${String(lots.length + 1).padStart(6, '0')}`;
    for (const e of ecritures) e.lot_demo = id;
    lots.push({
      id,
      ancre_id: ecritures[0].id,
      societe_id: ecritures[0].societe_id,
      etablissement_id: ecritures[0].etablissement_id,
      client_id: ecritures[0].client_id,
      nb_ecritures: ecritures.length,
      montant: eur(ecritures.reduce((s, e) => s + cent(e.montant), 0)),
      cohorte,
    });
    verites.push({
      objet_id: id,
      verdict,
      groupe_attendu: ecritures.map((e) => e.id).join('|'),
      methode_attendue: methode,
      code_scenario: scenario,
      note: notes || '',
    });
    return id;
  };

  /** Aligne une ecriture sur un client, une societe, un etablissement, un vehicule. */
  const caler = (e, modele, veh, cles) => {
    e.client_id = modele.client_id;
    e.societe_id = modele.societe_id;
    e.etablissement_id = modele.etablissement_id;
    e.vin = cles.vin ? veh.vin : null;
    e.immatriculation = cles.immat ? veh.immatriculation : null;
    e.ordre_reparation = cles.or ? modele.ordre_reparation || `ORD${veh.id.slice(-7)}` : null;
    e.reference_piece = cles.ref ? modele.reference_piece : null;
  };

  /** Un couple debit / credit du meme client, sur un meme vehicule. */
  const couple = (tirage, cles, options = {}) => {
    const paire = prendre(2);
    if (paire.length < 2) return null;
    const [debit, credit] = paire;
    const veh = vehiculeParId.get(debit.vehicule_id)
      || m.ref_vehicule[tirage.entier(0, m.ref_vehicule.length - 1)];
    const etab = etabParId.get(debit.etablissement_id) || m.ref_etablissement[0];

    const base = Math.round(tirage.montant(180, 24000) * 100) / 100;
    const ageJours = options.ageJours ?? tirage.entier(5, 900);
    const dateDebit = ajouterJours(m.meta.fin, -ageJours);

    debit.sens = 'D'; debit.compte = options.compte || '4111000'; debit.journal = 'VE';
    debit.montant = base; debit.date = dateDebit;
    debit.reference_piece = `F${debit.id.slice(-8)}`;
    debit.ordre_reparation = `ORD${veh.id.slice(-7)}`;
    debit.societe_id = etab.societe_id; debit.etablissement_id = etab.id;
    debit.lettrage = null; debit.facture_id = debit.facture_id || null;

    credit.sens = 'C'; credit.compte = options.compteCredit || '5120200';
    credit.journal = 'BQ';
    credit.montant = Math.round((base + (options.ecart || 0)) * 100) / 100;
    credit.date = ajouterJours(dateDebit, tirage.entier(1, 40));
    credit.client_id = debit.client_id;
    credit.societe_id = debit.societe_id; credit.etablissement_id = debit.etablissement_id;
    credit.lettrage = null; credit.facture_id = null;
    credit.reference_piece = null; credit.ordre_reparation = null;
    credit.vin = null; credit.immatriculation = null;

    caler(credit, debit, veh, cles);
    // Le debit porte toujours ses cles d'origine ; c'est le credit bancaire
    // qui en est plus ou moins pourvu, comme dans le reel.
    debit.vin = veh.vin;
    debit.immatriculation = veh.immatriculation;
    if (!cles.refDebit) debit.reference_piece = cles.ref ? debit.reference_piece : null;

    return { debit, credit, veh, base, etab };
  };

  /** Fabrique un lot pour un scenario donne. */
  const fabriquerLot = (tirage, cohorte) => {
    const scenario = tirage.poids(SCEN_LET);

    // ---- SC-05-01 : montant exact, cle forte presente des deux cotes
    if (scenario === 'SC-05-01') {
      const c = couple(tirage, { vin: true, ref: true, refDebit: true });
      if (!c) return;
      poser(scenario, cohorte, [c.debit, c.credit], VERDICTS.MATCH, 'M01');
      return;
    }

    // ---- SC-05-02 : une reference partagee en chaine, groupe transitif
    if (scenario === 'SC-05-02') {
      const trio = prendre(3);
      if (trio.length < 3) return;
      const veh = vehiculeParId.get(trio[0].vehicule_id) || m.ref_vehicule[0];
      const ref = `BORD${trio[0].id.slice(-7)}`;
      const a = Math.round(tirage.montant(400, 9000) * 100) / 100;
      const b = Math.round(tirage.montant(400, 9000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(20, 500));
      trio.forEach((e, k) => {
        e.client_id = trio[0].client_id;
        e.societe_id = trio[0].societe_id;
        e.etablissement_id = trio[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.reference_piece = ref;
        e.vin = k === 0 ? veh.vin : null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(2, 30));
        e.journal = k === 2 ? 'BQ' : 'VE';
        e.compte = k === 2 ? '5120200' : '4111000';
        e.sens = k === 2 ? 'C' : 'D';
        e.montant = k === 0 ? a : k === 1 ? b : Math.round((a + b) * 100) / 100;
      });
      poser(scenario, cohorte, trio, VERDICTS.MATCH, 'M02');
      return;
    }

    // ---- SC-05-03 / 04 / 05 : une seule cle sectorielle, et elle suffit
    if (scenario === 'SC-05-03' || scenario === 'SC-05-04' || scenario === 'SC-05-05') {
      const cles = scenario === 'SC-05-03' ? { vin: true }
        : scenario === 'SC-05-04' ? { immat: true } : { or: true };
      const c = couple(tirage, cles);
      if (!c) return;
      const methode = scenario === 'SC-05-03' ? 'M03' : scenario === 'SC-05-04' ? 'M04' : 'M05';
      poser(scenario, cohorte, [c.debit, c.credit], VERDICTS.MATCH, methode);
      return;
    }

    // ---- SC-05-06 : un reglement global egale la somme de trois ecritures
    if (scenario === 'SC-05-06') {
      const groupe = prendre(4);
      if (groupe.length < 4) return;
      const veh = vehiculeParId.get(groupe[0].vehicule_id) || m.ref_vehicule[0];
      const parts = [
        Math.round(tirage.montant(300, 7000) * 100) / 100,
        Math.round(tirage.montant(300, 7000) * 100) / 100,
        Math.round(tirage.montant(300, 7000) * 100) / 100,
      ];
      const total = eur(parts.reduce((s, x) => s + cent(x), 0));
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(30, 400));
      groupe.forEach((e, k) => {
        e.client_id = groupe[0].client_id;
        e.societe_id = groupe[0].societe_id;
        e.etablissement_id = groupe[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(3, 25));
        if (k < 3) {
          e.sens = 'D'; e.compte = '4111000'; e.journal = 'VE'; e.montant = parts[k];
          e.reference_piece = `F${e.id.slice(-8)}`; e.vin = veh.vin;
        } else {
          e.sens = 'C'; e.compte = '5120200'; e.journal = 'BQ'; e.montant = total;
          e.reference_piece = null; e.vin = veh.vin;
        }
      });
      poser(scenario, cohorte, groupe, VERDICTS.MATCH, 'M06');
      return;
    }

    // ---- SC-05-07 : aucune preuve suffisante isolement, cinq indices convergent
    if (scenario === 'SC-05-07') {
      // Ecart volontaire, dans la tolerance de l'anciennete choisie.
      const ageJours = tirage.entier(200, 350);
      const c = couple(tirage, { vin: true, immat: true, or: true }, {
        ageJours, ecart: Math.round(tirage.montant(6, 34) * 100) / 100,
      });
      if (!c) return;
      // Pas de reference de piece : c'est ce qui rend chaque cle insuffisante.
      c.debit.reference_piece = null;
      c.credit.reference_piece = null;
      poser(scenario, cohorte, [c.debit, c.credit], VERDICTS.MATCH, 'M01',
        'convergence de cinq indices, aucune cle suffisante seule');
      return;
    }

    // ---- SC-05-08 : meme montant, meme contexte, numero de serie contradictoire
    if (scenario === 'SC-05-08') {
      const c = couple(tirage, { vin: true });
      if (!c) return;
      // Le reglement porte le numero de serie d'un AUTRE vehicule, et ce
      // vehicule appartient a un autre compte client. La ressemblance est
      // parfaite, la contradiction est dure.
      let etrangere = null;
      for (let essai = 0; essai < 40 && etrangere === null; essai++) {
        const veh = m.ref_vehicule[tirage.entier(0, m.ref_vehicule.length - 1)];
        if (veh.client_id && veh.client_id !== c.debit.client_id) etrangere = veh;
      }
      if (etrangere === null) return;
      c.credit.vin = etrangere.vin;
      poser(scenario, cohorte, [c.debit, c.credit], VERDICTS.AUCUN, null,
        'numero de serie du reglement rattache a un autre compte client');
      return;
    }

    // ---- SC-05-09 : le meme ecart de 12 euros, a deux anciennetes
    if (scenario === 'SC-05-09') {
      const jeune = couple(tirage, { vin: true }, { ageJours: 20, ecart: 12 });
      if (!jeune) return;
      // A vingt jours, la tolerance est de cinq euros : douze ne passent pas.
      poser(scenario, cohorte, [jeune.debit, jeune.credit], VERDICTS.HUMAIN, null,
        'ecart de 12 EUR sur une creance de 20 jours, tolerance 5 EUR');
      const vieux = couple(tirage, { vin: true }, { ageJours: 425, ecart: 12 });
      if (!vieux) return;
      // A quatorze mois, la tolerance est de cent euros : douze passent.
      poser(scenario, cohorte, [vieux.debit, vieux.credit], VERDICTS.MATCH, 'M01',
        'ecart de 12 EUR sur une creance de 14 mois, tolerance 100 EUR');
      return;
    }

    // ---- SC-05-10 : ressemblance forte, mais le groupe ne solde pas
    if (scenario === 'SC-05-10') {
      const c = couple(tirage, { vin: true, ref: true, refDebit: true }, {
        ageJours: tirage.entier(10, 50),
        // Un ecart largement hors barème a cette anciennete.
        ecart: Math.round(tirage.montant(180, 900) * 100) / 100,
      });
      if (!c) return;
      poser(scenario, cohorte, [c.debit, c.credit], VERDICTS.HUMAIN, null,
        'toutes les cles concordent, le solde du groupe ne serait pas conforme');
      return;
    }

    // ---- SC-05-11 : deux sous-ensembles equilibrent exactement le meme total
    if (scenario === 'SC-05-11') {
      const groupe = prendre(5);
      if (groupe.length < 5) return;
      const veh = vehiculeParId.get(groupe[0].vehicule_id) || m.ref_vehicule[0];
      const a = Math.round(tirage.montant(500, 5000) * 100) / 100;
      const b = Math.round(tirage.montant(500, 5000) * 100) / 100;
      const ecart = Math.round(tirage.montant(40, 300) * 100) / 100;
      if (b - ecart <= 40) return;
      const total = eur(cent(a) + cent(b));
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(40, 500));
      const montants = [a, b, Math.round((a + ecart) * 100) / 100, Math.round((b - ecart) * 100) / 100];
      groupe.forEach((e, k) => {
        e.client_id = groupe[0].client_id;
        e.societe_id = groupe[0].societe_id;
        e.etablissement_id = groupe[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.reference_piece = null;
        e.vin = veh.vin;
        e.date = ajouterJours(dateA, k * tirage.entier(2, 20));
        if (k < 4) {
          e.sens = 'D'; e.compte = '4111000'; e.journal = 'VE'; e.montant = montants[k];
        } else {
          e.sens = 'C'; e.compte = '5120200'; e.journal = 'BQ'; e.montant = total;
        }
      });
      poser(scenario, cohorte, groupe, VERDICTS.HUMAIN, null,
        'deux sous-ensembles equilibrent le meme total, aucun signal ne departage');
      return;
    }

    // ---- SC-05-12 : un reglement isole, sans contrepartie possible
    if (scenario === 'SC-05-12') {
      const seul = prendre(1);
      if (seul.length < 1) return;
      const e = seul[0];
      e.sens = 'C'; e.compte = '5120200'; e.journal = 'BQ';
      e.montant = Math.round(tirage.montant(90, 6000) * 100) / 100;
      e.date = ajouterJours(m.meta.fin, -tirage.entier(5, 300));
      e.lettrage = null; e.facture_id = null;
      e.reference_piece = null; e.vin = null;
      e.immatriculation = null; e.ordre_reparation = null;
      poser(scenario, cohorte, [e], VERDICTS.AUCUN, null,
        'aucune ecriture de sens oppose sur ce compte client');
      return;
    }

    // ---- SC-05-14 : solde net entre deux societes
    if (scenario === 'SC-05-14') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const [a, b] = paire;
      const socA = m.ref_societe[tirage.entier(0, m.ref_societe.length - 1)];
      let socB = socA;
      for (let essai = 0; essai < 20 && socB.id === socA.id; essai++) {
        socB = m.ref_societe[tirage.entier(0, m.ref_societe.length - 1)];
      }
      if (socB.id === socA.id) return;
      const montant = Math.round(tirage.montant(600, 18000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(20, 400));
      a.societe_id = socA.id; b.societe_id = socB.id;
      a.client_id = b.client_id; // meme tiers de liaison
      a.sens = 'D'; a.compte = '4118000'; a.journal = 'OD'; a.montant = montant; a.date = dateA;
      b.sens = 'C'; b.compte = '4018000'; b.journal = 'OD';
      b.montant = Math.round((montant + tirage.montant(0, 4)) * 100) / 100;
      b.date = ajouterJours(dateA, tirage.entier(0, 20));
      for (const e of [a, b]) {
        e.lettrage = null; e.facture_id = null;
        e.reference_piece = 'LIAISON'; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
      }
      poser(scenario, cohorte, [a, b], VERDICTS.MATCH, 'M07');
      return;
    }

    // ---- SC-05-16 : compte d'attente ancien, solde negligeable (M00)
    if (scenario === 'SC-05-16') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const base = Math.round(tirage.montant(400, 6000) * 100) / 100;
      const residu = Math.round(tirage.montant(1, 18) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(200, 700));
      paire.forEach((e, k) => {
        e.client_id = paire[0].client_id;
        e.societe_id = paire[0].societe_id;
        e.etablissement_id = paire[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.compte = '471000'; e.journal = 'OD';
        e.reference_piece = null; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(1, 30));
        e.sens = k === 0 ? 'D' : 'C';
        e.montant = k === 0 ? Math.round((base + residu) * 100) / 100 : base;
      });
      poser(scenario, cohorte, paire, VERDICTS.MATCH, 'M00');
      return;
    }

    // ---- SC-05-17 : acompte dormant (M09)
    if (scenario === 'SC-05-17') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const acompte = tirage.chance(0.5) ? 49 : 199;
      const base = Math.round(tirage.montant(500, 5000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(160, 500));
      paire.forEach((e, k) => {
        e.client_id = paire[0].client_id;
        e.societe_id = paire[0].societe_id;
        e.etablissement_id = paire[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.compte = '4111000'; e.journal = k === 0 ? 'VE' : 'BQ';
        e.reference_piece = null; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(1, 25));
        e.sens = k === 0 ? 'D' : 'C';
        e.montant = k === 0 ? Math.round((base + acompte) * 100) / 100 : base;
      });
      poser(scenario, cohorte, paire, VERDICTS.MATCH, 'M09');
      return;
    }

    // ---- SC-05-18 : deux comptes clients a soldes opposes (M10)
    if (scenario === 'SC-05-18') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const veh = vehiculeParId.get(paire[0].vehicule_id) || m.ref_vehicule[0];
      const base = Math.round(tirage.montant(300, 9000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(60, 400));
      paire.forEach((e, k) => {
        e.client_id = paire[0].client_id;
        e.societe_id = paire[0].societe_id;
        e.etablissement_id = paire[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.compte = k === 0 ? '4111000' : '4114000';
        e.journal = k === 0 ? 'VE' : 'OD';
        e.reference_piece = null;
        e.vin = veh.vin;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(1, 20));
        e.sens = k === 0 ? 'D' : 'C';
        e.montant = k === 0 ? base : Math.round((base - tirage.montant(0, 1.6)) * 100) / 100;
      });
      poser(scenario, cohorte, paire, VERDICTS.MATCH, 'M10');
      return;
    }

    // ---- SC-05-19 : ecritures de plus de deux ans (M17)
    if (scenario === 'SC-05-19') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const base = Math.round(tirage.montant(2000, 18000) * 100) / 100;
      const residu = Math.round(tirage.montant(30, 400) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(760, 1100));
      paire.forEach((e, k) => {
        e.client_id = paire[0].client_id;
        e.societe_id = paire[0].societe_id;
        e.etablissement_id = paire[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.compte = '4111000'; e.journal = k === 0 ? 'VE' : 'BQ';
        e.reference_piece = null; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(1, 40));
        e.sens = k === 0 ? 'D' : 'C';
        e.montant = k === 0 ? Math.round((base + residu) * 100) / 100 : base;
      });
      poser(scenario, cohorte, paire, VERDICTS.MATCH, 'M17');
      return;
    }

    // ---- SC-05-20 : compte client ferme (M18)
    if (scenario === 'SC-05-20') {
      const seul = prendre(1);
      if (seul.length < 1) return;
      const e = seul[0];
      e.compte = '4111000'; e.journal = 'OD';
      e.code_client_demo = 'ex-' + String(seul[0].id).slice(-6);
      e.sens = 'D';
      e.montant = Math.round(tirage.montant(40, 900) * 100) / 100;
      e.date = ajouterJours(m.meta.fin, -tirage.entier(200, 800));
      e.lettrage = null; e.facture_id = null;
      e.reference_piece = null; e.vin = null;
      e.immatriculation = null; e.ordre_reparation = null;
      poser(scenario, cohorte, [e], VERDICTS.MATCH, 'M18');
      return;
    }

    // ---- SC-05-21 : liaison entre deux etablissements d'une meme societe (M19)
    if (scenario === 'SC-05-21') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const soc = m.ref_societe[tirage.entier(0, m.ref_societe.length - 1)];
      const etabs = m.ref_etablissement.filter((x) => x.societe_id === soc.id);
      if (etabs.length < 2) return;
      const montant = Math.round(tirage.montant(400, 12000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(30, 350));
      paire.forEach((e, k) => {
        e.client_id = paire[0].client_id;
        e.societe_id = soc.id;
        e.etablissement_id = etabs[k % etabs.length].id;
        e.lettrage = null; e.facture_id = null;
        e.compte = k === 0 ? '4118000' : '4018000';
        e.journal = 'OD';
        e.reference_piece = 'LIAISON'; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(0, 15));
        e.sens = k === 0 ? 'D' : 'C';
        e.montant = k === 0 ? montant : Math.round((montant - tirage.montant(0, 3)) * 100) / 100;
      });
      poser(scenario, cohorte, paire, VERDICTS.MATCH, 'M19');
      return;
    }

    // ---- SC-05-22 : nom de client unique dans la societe (M20)
    if (scenario === 'SC-05-22') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const base = Math.round(tirage.montant(200, 4000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(120, 400));
      paire.forEach((e, k) => {
        e.client_id = paire[0].client_id;
        e.societe_id = paire[0].societe_id;
        e.etablissement_id = paire[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.compte = '4111000'; e.journal = k === 0 ? 'VE' : 'BQ';
        e.reference_piece = null; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(1, 30));
        e.sens = k === 0 ? 'D' : 'C';
        e.montant = k === 0 ? Math.round((base + tirage.montant(1, 20)) * 100) / 100 : base;
      });
      poser(scenario, cohorte, paire, VERDICTS.MATCH, 'M20');
      return;
    }

    // ---- SC-05-23 : solde client strictement nul (M22)
    if (scenario === 'SC-05-23') {
      const trio = prendre(3);
      if (trio.length < 3) return;
      const a = Math.round(tirage.montant(300, 4000) * 100) / 100;
      const b = Math.round(tirage.montant(300, 4000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(40, 400));
      trio.forEach((e, k) => {
        e.client_id = trio[0].client_id;
        e.societe_id = trio[0].societe_id;
        e.etablissement_id = trio[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.compte = '4111000'; e.journal = k === 2 ? 'BQ' : 'VE';
        e.reference_piece = null; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k * tirage.entier(2, 25));
        e.sens = k === 2 ? 'C' : 'D';
        e.montant = k === 0 ? a : k === 1 ? b : eur(cent(a) + cent(b));
      });
      poser(scenario, cohorte, trio, VERDICTS.MATCH, 'M22');
      return;
    }

    // ---- SC-05-24 : dossier comptant qui solde exactement (M26)
    if (scenario === 'SC-05-24') {
      const paire = prendre(2);
      if (paire.length < 2) return;
      const montant = Math.round(tirage.montant(600, 22000) * 100) / 100;
      const dateA = ajouterJours(m.meta.fin, -tirage.entier(20, 300));
      paire.forEach((e, k) => {
        e.client_id = paire[0].client_id;
        e.societe_id = paire[0].societe_id;
        e.etablissement_id = paire[0].etablissement_id;
        e.lettrage = null; e.facture_id = null;
        e.compte = '4111000'; e.journal = k === 0 ? 'VE' : 'CA';
        e.reference_piece = 'COMPTANT'; e.vin = null;
        e.immatriculation = null; e.ordre_reparation = null;
        e.date = ajouterJours(dateA, k);
        e.sens = k === 0 ? 'D' : 'C';
        e.montant = montant;
      });
      poser(scenario, cohorte, paire, VERDICTS.MATCH, 'M26');
      return;
    }

    // ---- SC-05-15 : garantie constructeur, ecart en deca ou au dela du seuil
    if (scenario === 'SC-05-15') {
      const grosEcart = tirage.chance(0.28);
      const c = couple(tirage, { or: true, vin: true }, {
        compte: '4116000', compteCredit: '4116000',
        ageJours: tirage.entier(30, 400),
        ecart: grosEcart
          ? Math.round(tirage.montant(520, 2400) * 100) / 100
          : Math.round(tirage.montant(2, 45) * 100) / 100,
      });
      if (!c) return;
      poser(scenario, cohorte, [c.debit, c.credit],
        grosEcart ? VERDICTS.HUMAIN : VERDICTS.MATCH,
        grosEcart ? null : 'M23',
        grosEcart ? 'ecart de garantie au-dela de 500 EUR : anomalie, pas lettrage' : '');
      return;
    }
  };

  // ---------------------------------------------------- les trois populations
  const VOLUME = { CALIBRATION_O5: 900, VALIDATION_O5: 900 };
  for (const [cohorte, n] of Object.entries(VOLUME)) {
    for (let i = 0; i < n; i++) fabriquerLot(rnd, cohorte);
  }
  // Premiere population aveugle. Elle a servi au diagnostic : deux de ses faux
  // positifs ont ete ouverts, elle est donc devenue un jeu de validation.
  for (let i = 0; i < 1400; i++) fabriquerLot(rndBlind, 'BLIND_O5');

  // Deuxieme population aveugle. Ses trois faux positifs ont ete ouverts pour
  // comprendre : elle est devenue a son tour un jeu de validation.
  const rndBlind2 = generateur(GRAINE_O5_BLIND_2);
  for (let i = 0; i < 1600; i++) fabriquerLot(rndBlind2, 'BLIND_O5_2');

  // Le test final, tire apres le gel complet. Mesure une seule fois.
  const rndBlind3 = generateur(GRAINE_O5_BLIND_3);
  for (let i = 0; i < 1800; i++) fabriquerLot(rndBlind3, 'BLIND_O5_3');

  // ------------------------------------- les lettrages deja poses, a relire
  //
  // Un moteur qui n'automatiserait que l'avenir laisserait derriere lui tout ce
  // que six mois de lettrage manuel ont pu poser de travers. La relecture est
  // une capacite a part entiere, et elle a besoin de cas faux.
  const historiques = [];
  const veritesHisto = [];
  for (let i = 0; i < 700; i++) {
    const etat = rnd.poids(ETATS_HISTORIQUES);
    const taille = etat === 'doublon' ? 3 : 2;
    const lot = prendre(taille);
    if (lot.length < taille) break;

    const veh = vehiculeParId.get(lot[0].vehicule_id) || m.ref_vehicule[0];
    const code = `LET${String(900000 + i)}`;
    const base = Math.round(rnd.montant(200, 12000) * 100) / 100;
    const dateA = ajouterJours(m.meta.fin, -rnd.entier(20, 180));

    lot.forEach((e, k) => {
      e.client_id = lot[0].client_id;
      e.societe_id = lot[0].societe_id;
      e.etablissement_id = lot[0].etablissement_id;
      e.facture_id = null;
      e.lettrage = code;
      e.date = ajouterJours(dateA, k * rnd.entier(1, 20));
      e.reference_piece = k === 0 ? `F${e.id.slice(-8)}` : null;
      e.immatriculation = null; e.ordre_reparation = null;
      e.vin = veh.vin;
      e.sens = k === 0 ? 'D' : 'C';
      e.compte = k === 0 ? '4111000' : '5120200';
      e.journal = k === 0 ? 'VE' : 'BQ';
      e.montant = base;
    });

    if (etat === 'solde_incorrect') {
      // Le groupe ne solde pas : c'est verifiable sans rien savoir d'autre.
      lot[1].montant = Math.round((base - rnd.montant(30, 700)) * 100) / 100;
    }
    if (etat === 'contradictoire') {
      // Le numero de serie du reglement appartient a un autre compte.
      let etrangere = null;
      for (let essai = 0; essai < 40 && etrangere === null; essai++) {
        const v = m.ref_vehicule[rnd.entier(0, m.ref_vehicule.length - 1)];
        if (v.client_id && v.client_id !== lot[0].client_id) etrangere = v;
      }
      if (etrangere) lot[1].vin = etrangere.vin;
      else continue;
    }
    if (etat === 'mauvaise_cle') {
      // Le lettrage a ete pose sur une reference generique, qui ne prouve rien.
      for (const e of lot) { e.reference_piece = 'ASOLDER'; e.vin = null; }
    }
    if (etat === 'doublon') {
      // Deux reglements pour une seule facture : le groupe solde deux fois.
      lot[2].sens = 'C'; lot[2].compte = '5120200'; lot[2].journal = 'BQ';
      lot[2].montant = base;
    }

    historiques.push({
      code_lettrage: code,
      societe_id: lot[0].societe_id,
      etablissement_id: lot[0].etablissement_id,
      client_id: lot[0].client_id,
      nb_ecritures: lot.length,
      montant_debit: eur(lot.filter((e) => e.sens === 'D').reduce((s, e) => s + cent(e.montant), 0)),
      montant_credit: eur(lot.filter((e) => e.sens === 'C').reduce((s, e) => s + cent(e.montant), 0)),
      pose_le: lot[lot.length - 1].date,
    });
    veritesHisto.push({
      objet_id: code,
      etat_reel: etat,
      a_revoir: etat !== 'coherent',
      ecritures: lot.map((e) => e.id).join('|'),
    });
  }

  // Le drapeau de prise est interne a la fabrique : il ne sort pas.
  for (const e of m.ops_ecriture) delete e._o5;

  m.ops_lettrage_lot = lots;
  m.ops_lettrage_historique = historiques;

  return { verites, veritesHisto };
}
