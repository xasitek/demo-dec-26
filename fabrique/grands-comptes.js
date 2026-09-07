// Fabrique de l'outil 7 — securiser les dossiers grands comptes. GROUPE SYNTHAUTO.
//
// ADDITIVE, et tiree en DERNIER sur sa propre graine. Les outils 4, 5 et 6 sont
// figes et leurs tests finaux sont publies : ce module n'ecrit que des tables
// NOUVELLES et ne modifie aucune ligne existante -- ni facture, ni ecriture, ni
// virement, ni dossier, ni cause d'ouverture. Il LIT les 3 400 dossiers de
// livraison deja plantes dans le monde et leur ajoute les deux couches qui
// manquaient pour qu'il y ait quelque chose a controler :
//
//   1. la GRILLE DOCUMENTAIRE de chaque loueur, reellement differente d'un
//      loueur a l'autre. Aucune piece n'est obligatoire universellement : c'est
//      la grille du payeur qui commande, et c'est tout l'objet de l'outil ;
//   2. les VALEURS de chaque piece -- ce que la secretaire a declare, et ce que
//      le controle lit sur le document. Sans elles, il n'y a rien a verifier :
//      « piece presente » ne dit pas si le numero de commande est le bon.
//
// La verite -- quelles anomalies sont reellement plantees dans quel dossier --
// vit dans le schema de verite, physiquement separe. Ce module ne la lit jamais
// pour decider : il la POSE, et le moteur la retrouvera ou non.

import { generateur, ibanDeterministe, GRAINE_O7, GRAINE_O7_BLIND } from './aleatoire.js';

/**
 * Les sept types de piece. Le noyau historique -- PVL, BDC, CPI, F1, F2 --
 * plus la carte grise et le RIB, que certaines grilles exigent.
 */
export const TYPES_PIECE = [
  ['PVL', 'PV de livraison', 'Signé et tamponné par le client, il prouve la livraison.'],
  ['BDC', 'Bon de commande', 'Il porte le numéro de commande que la facture doit reprendre.'],
  ['CPI', 'Certificat provisoire d\'immatriculation', 'Il atteste la mise en circulation.'],
  ['F1', 'Facture du véhicule', 'Adressée au loueur, jamais au conducteur.'],
  ['F2', 'Facture des accessoires et options', 'Séparée quand le loueur la refacture autrement.'],
  ['CG', 'Carte grise', 'Exigée par les loueurs qui immatriculent à leur nom.'],
  ['RIB', 'Relevé d\'identité bancaire', 'Exigé au premier règlement d\'un nouveau payeur.'],
];

/**
 * Les huit grilles documentaires, une par loueur, VRAIMENT differentes.
 *
 * C'est le coeur de l'outil : le meme vehicule livre a deux loueurs ne demande
 * pas le meme dossier. Un dossier juge complet chez l'un est incomplet chez
 * l'autre, et l'ecran doit le montrer -- sinon on relance un site pour une
 * piece que son payeur ne demande pas.
 *
 * `exigence` prend cinq valeurs :
 *   obligatoire        toujours attendue
 *   si_electrique      attendue seulement pour un vehicule electrique ou hybride
 *   si_premier_reglt   attendue au premier reglement de ce payeur
 *   optionnelle        acceptee, jamais bloquante
 *   non_demandee       ne figure pas dans la grille de ce loueur
 */
const GRILLES = {
  'LOU-01': { // ALPHA : le plus exigeant, immatricule a son nom
    nom: 'LOUEUR ALPHA', delai: 45, pv_electronique: false, tampon: true,
    tolerance_centimes: 0,
    grille: { PVL: 'obligatoire', BDC: 'obligatoire', CPI: 'obligatoire', F1: 'obligatoire',
      F2: 'obligatoire', CG: 'obligatoire', RIB: 'si_premier_reglt' },
  },
  'LOU-02': { // BETA : accepte le PV electronique, ne veut pas de carte grise
    nom: 'LOUEUR BETA', delai: 30, pv_electronique: true, tampon: false,
    tolerance_centimes: 2,
    grille: { PVL: 'obligatoire', BDC: 'obligatoire', CPI: 'obligatoire', F1: 'obligatoire',
      F2: 'optionnelle', CG: 'non_demandee', RIB: 'non_demandee' },
  },
  'LOU-03': { // GAMMA : dossier minimal, mais tolerance zero sur le montant
    nom: 'LOUEUR GAMMA', delai: 60, pv_electronique: true, tampon: false,
    tolerance_centimes: 0,
    grille: { PVL: 'obligatoire', BDC: 'non_demandee', CPI: 'obligatoire', F1: 'obligatoire',
      F2: 'non_demandee', CG: 'non_demandee', RIB: 'non_demandee' },
  },
  'LOU-04': { // DELTA : exige la carte grise et le RIB, pas le CPI
    nom: 'LOUEUR DELTA', delai: 45, pv_electronique: false, tampon: true,
    tolerance_centimes: 1,
    grille: { PVL: 'obligatoire', BDC: 'obligatoire', CPI: 'non_demandee', F1: 'obligatoire',
      F2: 'obligatoire', CG: 'obligatoire', RIB: 'obligatoire' },
  },
  'LOU-05': { // EPSILON : deux factures separees, batterie exigee
    nom: 'LOUEUR EPSILON', delai: 30, pv_electronique: true, tampon: true,
    tolerance_centimes: 5,
    grille: { PVL: 'obligatoire', BDC: 'obligatoire', CPI: 'optionnelle', F1: 'obligatoire',
      F2: 'obligatoire', CG: 'si_electrique', RIB: 'non_demandee' },
  },
  'LOU-06': { // ZETA : le plus souple
    nom: 'LOUEUR ZETA', delai: 60, pv_electronique: true, tampon: false,
    tolerance_centimes: 10,
    grille: { PVL: 'obligatoire', BDC: 'optionnelle', CPI: 'optionnelle', F1: 'obligatoire',
      F2: 'optionnelle', CG: 'non_demandee', RIB: 'non_demandee' },
  },
  'LOU-07': { // ETA : carte grise si electrique, RIB au premier reglement
    nom: 'LOUEUR ETA', delai: 45, pv_electronique: false, tampon: true,
    tolerance_centimes: 0,
    grille: { PVL: 'obligatoire', BDC: 'obligatoire', CPI: 'obligatoire', F1: 'obligatoire',
      F2: 'non_demandee', CG: 'si_electrique', RIB: 'si_premier_reglt' },
  },
  'LOU-08': { // THETA : exige tout, tampon compris
    nom: 'LOUEUR THETA', delai: 30, pv_electronique: false, tampon: true,
    tolerance_centimes: 0,
    grille: { PVL: 'obligatoire', BDC: 'obligatoire', CPI: 'obligatoire', F1: 'obligatoire',
      F2: 'obligatoire', CG: 'obligatoire', RIB: 'obligatoire' },
  },
};

/**
 * Le referentiel des anomalies, tire des ecrans reels du groupe.
 *
 * Chaque code porte sa piece, sa gravite, et sa SUITE. La suite n'est pas
 * decorative : une anomalie sur la facture se corrige par un avoir et une
 * refacturation, jamais par une modification directe du document -- une facture
 * emise ne se retouche pas. Les autres se corrigent en redeposant la piece.
 */
export const ANOMALIES = [
  // ---- bon de commande
  ['BDC_NUM_ABSENT', 'BDC', 'bloquante', 'Numéro de commande absent de la facture', 'avoir_refacturation'],
  ['BDC_NUM_DIVERGENT', 'BDC', 'bloquante', 'Numéro de facture différent du numéro de bon de commande', 'avoir_refacturation'],
  ['BDC_NUM_ILLISIBLE', 'BDC', 'majeure', 'Numéro de commande illisible ou incomplet', 'redeposer'],
  ['BDC_MONTANT_DIVERGENT', 'BDC', 'bloquante', 'Montant du bon de commande différent du montant facturé', 'avoir_refacturation'],
  // ---- batterie des vehicules electriques et hybrides
  ['BAT_PRIX_ABSENT', 'F1', 'bloquante', 'Prix de la batterie absent (véhicule électrique ou hybride)', 'avoir_refacturation'],
  ['BAT_HT_MANQUANT', 'F1', 'majeure', 'Montant HT de la batterie manquant', 'avoir_refacturation'],
  ['BAT_TTC_MANQUANT', 'F1', 'majeure', 'Montant TTC de la batterie manquant', 'avoir_refacturation'],
  ['BAT_MENTION_MANQUANTE', 'F1', 'mineure', 'Mention HT ou TTC manquante après le prix', 'avoir_refacturation'],
  // ---- PV de livraison
  ['PVL_NON_DATE_NON_SIGNE', 'PVL', 'bloquante', 'PV de livraison ni daté ni signé', 'redeposer'],
  ['PVL_NON_TAMPONNE', 'PVL', 'majeure', 'PV de livraison non tamponné', 'redeposer'],
  ['PVL_IMMAT_DIVERGENTE', 'PVL', 'bloquante', 'Immatriculation du PV différente de celle du dossier', 'redeposer'],
  // ---- pieces absentes et facturation
  ['CPI_ABSENT', 'CPI', 'bloquante', 'Certificat provisoire d\'immatriculation absent du dossier', 'redeposer'],
  ['FACT_ADRESSE_CLIENT', 'F1', 'bloquante', 'Facture adressée au conducteur au lieu du loueur', 'avoir_refacturation'],
  ['DOSSIER_NON_DECLARE', 'PVL', 'bloquante', 'Véhicule non déclaré livré, ou sans PV de livraison', 'declarer'],
];

/**
 * Les seize scenarios de l'outil 7.
 *
 * Ils sont plus fins que les six scenarios que le monde porte deja sur ces
 * memes dossiers (`SC-07-01` a `SC-07-06`, conserves tels quels) : ceux-la
 * disaient qu'un dossier etait bloque, ceux-ci disent PAR QUOI.
 */
const SCENARIOS = [
  ['SC-07-11', 0.32, 'Dossier complet et conforme', []],
  ['SC-07-12', 0.09, 'PV de livraison ni daté ni signé', ['PVL_NON_DATE_NON_SIGNE']],
  ['SC-07-13', 0.05, 'PV non tamponné, chez un loueur qui l\'exige', ['PVL_NON_TAMPONNE']],
  ['SC-07-14', 0.06, 'Numéro de commande absent de la facture', ['BDC_NUM_ABSENT']],
  ['SC-07-15', 0.05, 'Numéro de facture divergent du bon de commande', ['BDC_NUM_DIVERGENT']],
  ['SC-07-16', 0.05, 'Montant du bon de commande divergent du facturé', ['BDC_MONTANT_DIVERGENT']],
  ['SC-07-17', 0.05, 'Prix de la batterie absent sur un véhicule électrifié', ['BAT_PRIX_ABSENT']],
  ['SC-07-18', 0.03, 'Mention HT ou TTC manquante après le prix de la batterie', ['BAT_MENTION_MANQUANTE']],
  ['SC-07-19', 0.05, 'CPI absent, chez un loueur qui l\'exige', ['CPI_ABSENT']],
  ['SC-07-20', 0.04, 'Immatriculation du PV divergente du dossier', ['PVL_IMMAT_DIVERGENTE']],
  ['SC-07-21', 0.04, 'Facture adressée au conducteur au lieu du loueur', ['FACT_ADRESSE_CLIENT']],
  ['SC-07-22', 0.05, 'Quatre anomalies cumulées sur le même dossier',
    ['BDC_NUM_DIVERGENT', 'BDC_MONTANT_DIVERGENT', 'BAT_PRIX_ABSENT', 'PVL_NON_TAMPONNE']],
  ['SC-07-23', 0.03, 'Véhicule non déclaré livré', ['DOSSIER_NON_DECLARE']],
  // Les deux scenarios qui font la doctrine.
  ['SC-07-24', 0.04, 'Numéro illisible : le doute ne se tranche pas seul', ['BDC_NUM_ILLISIBLE']],
  ['SC-07-25', 0.02, 'Pièce absente que la grille du loueur ne demande pas', []],
  ['SC-07-26', 0.01, 'Écart de montant dans la tolérance du loueur', []],
  ['SC-07-27', 0.02, 'Montants HT et TTC de la batterie manquants',
    ['BAT_HT_MANQUANT', 'BAT_TTC_MANQUANT']],
];

const ENERGIES_BATTERIE = new Set(['electrique', 'hybride']);

/** Deux chiffres, comme un identifiant du monde. */
const idGc = (prefixe, n, taille) => `${prefixe}-${String(n).padStart(taille, '0')}`;

/**
 * Plante l'outil 7. Retourne le compte des lignes ecrites et la verite.
 *
 * @param {object} m le monde deja construit. LU, jamais modifie.
 */
export function planterGrandsComptes(m) {
  const rnd = generateur(GRAINE_O7);
  const rndAveugle = generateur(GRAINE_O7_BLIND);

  const vehiculeParId = new Map(m.ref_vehicule.map((v) => [v.id, v]));
  const factureParId = new Map(m.ops_facture.map((f) => [f.id, f]));
  const etabParId = new Map(m.ref_etablissement.map((e) => [e.id, e]));
  const secretaires = m.ref_utilisateur.filter((u) => u.role === 'secretaire');

  // ---------------------------------------------------------------- la grille
  m.ref_type_piece = TYPES_PIECE.map(([code, libelle, role], i) => ({
    code, libelle, role, rang: i + 1,
  }));

  m.ref_loueur_grille = [];
  m.ref_exigence_piece = [];
  for (const [loueurId, g] of Object.entries(GRILLES)) {
    m.ref_loueur_grille.push({
      loueur_id: loueurId,
      nom: g.nom,
      delai_paiement: g.delai,
      accepte_pv_electronique: g.pv_electronique,
      exige_tampon: g.tampon,
      tolerance_centimes: g.tolerance_centimes,
      nb_obligatoires: Object.values(g.grille).filter((e) => e === 'obligatoire').length,
    });
    for (const [type, exigence] of Object.entries(g.grille)) {
      m.ref_exigence_piece.push({ loueur_id: loueurId, type_piece: type, exigence });
    }
  }

  m.ref_anomalie = ANOMALIES.map(([code, piece, gravite, libelle, suite], i) => ({
    code, type_piece: piece, gravite, libelle, suite, rang: i + 1,
  }));

  m.ref_scenario_gc = SCENARIOS.map(([code, poids, libelle, codes]) => ({
    code, poids, libelle, anomalies: codes.join('|'), nb_anomalies: codes.length,
  }));

  // ------------------------------------------------------------- les dossiers
  //
  // Exactement les 3 400 dossiers que le monde porte deja, et exactement ceux
  // vers lesquels la file « pieces manquantes » de l'outil 6 pointe. On ne
  // recree aucune population : on enrichit celle qui existe.
  m.ops_dossier_gc = [];
  m.ops_piece_gc = [];
  const verites = [];
  const veritesAnomalies = [];
  let premierReglement = new Set();

  const total = m.ops_dossier_livraison.length;
  m.ops_dossier_livraison.forEach((source, i) => {
    // La cohorte est deterministe : la moitie en calibration, un quart en
    // validation, un quart en aveugle. Le tirage de l'aveugle se fait sur SA
    // graine, pour que le reglage sur la calibration ne puisse pas le toucher.
    const cohorte = i % 4 === 3 ? 'BLIND_O7' : (i % 4 === 2 ? 'VALIDATION_O7' : 'CALIBRATION_O7');
    const tirage = 'BLIND_O7' === cohorte ? rndAveugle : rnd;

    const grille = GRILLES[source.loueur_id];
    const veh = vehiculeParId.get(source.vehicule_id);
    const fac = factureParId.get(source.facture_id);
    const etab = etabParId.get(source.etablissement_id);
    // Un scenario n'est tirable que s'il est APPLICABLE a ce dossier : sa
    // grille doit rendre l'anomalie possible, et l'energie du vehicule aussi.
    // Sans ce filtre, « quatre anomalies cumulees » tombait sur un loueur sans
    // bon de commande et sur un vehicule a essence, et il ne restait rien a
    // reprocher : le scenario mentait, et le contrat l'a vu.
    const electrifiePrealable = veh ? ENERGIES_BATTERIE.has(veh.energie) : false;
    const possible = (code) => {
      if ('PVL_NON_TAMPONNE' === code) return grille.tampon;
      if ('CPI_ABSENT' === code) return 'obligatoire' === grille.grille.CPI;
      if (code.startsWith('BDC_')) return 'non_demandee' !== grille.grille.BDC;
      if (code.startsWith('BAT_')) return electrifiePrealable;
      return true;
    };
    const applicables = SCENARIOS.filter(([code, , , codes]) => {
      if (!codes.every(possible)) return false;
      // Un ecart tolere exige un loueur qui declare une tolerance, et un bon
      // de commande pour le porter.
      if ('SC-07-26' === code) {
        return grille.tolerance_centimes > 0 && 'non_demandee' !== grille.grille.BDC;
      }
      // Une piece optionnelle absente exige une piece optionnelle.
      if ('SC-07-25' === code) {
        return Object.values(grille.grille).includes('optionnelle');
      }
      return true;
    });
    const scenario = tirage.poids(applicables.map(([c, p]) => [c, p]));
    const def = SCENARIOS.find(([c]) => c === scenario);
    const anomaliesVoulues = new Set(def[3]);

    // Le premier reglement d'un payeur : le RIB n'est exige qu'une fois.
    const clePremier = `${source.loueur_id}`;
    const estPremierReglement = !premierReglement.has(clePremier);
    premierReglement.add(clePremier);

    const electrifie = electrifiePrealable;
    const secretaire = secretaires.length > 0
      ? secretaires[i % secretaires.length]
      : { id: 'UTI-000', nom: 'secrétaire' };

    const dossier = {
      id: source.id,
      loueur_id: source.loueur_id,
      facture_id: source.facture_id,
      vehicule_id: source.vehicule_id,
      immatriculation: veh ? veh.immatriculation : null,
      vin: veh ? veh.serie : null,
      vin8: veh ? veh.serie8 : null,
      energie: veh ? veh.energie : null,
      modele: veh ? veh.modele : null,
      etablissement_id: source.etablissement_id,
      societe_id: etab ? etab.societe_id : null,
      montant_facture: source.montant,
      numero_facture: fac ? fac.numero : null,
      date_livraison: source.date,
      secretaire_id: secretaire.id,
      cohorte,
      code_scenario: scenario,
      // L'etat du dossier dans le circuit. `a_verifier` est l'etat de depart
      // de la demonstration : la secretaire a depose, le comptable n'a pas
      // encore instruit.
      statut: 'a_verifier',
      premier_reglement: estPremierReglement,
    };
    m.ops_dossier_gc.push(dossier);

    // ---------------------------------------------------------- les pieces
    //
    // La grille du loueur commande. Une piece `non_demandee` n'est pas creee :
    // elle ne doit jamais apparaitre comme manquante, et c'est precisement la
    // faute que l'outil corrige.
    const attendues = [];
    for (const [type, exigence] of Object.entries(grille.grille)) {
      if ('non_demandee' === exigence) continue;
      if ('si_electrique' === exigence && !electrifie) continue;
      if ('si_premier_reglt' === exigence && !estPremierReglement) continue;
      attendues.push([type, exigence]);
    }

    const numeroBdc = `BC${String(1000000 + (i * 7919) % 8999999)}`;
    const montantCentimes = Math.round(source.montant * 100);

    for (const [type, exigence] of attendues) {
      const piece = {
        id: idGc('PGC', m.ops_piece_gc.length + 1, 7),
        dossier_id: dossier.id,
        type_piece: type,
        exigence,
        presente: true,
        lisible: true,
        // Ce que la secretaire declare au depot.
        numero_declare: null, montant_declare: null, date_declaree: null,
        // Ce que le controle lit sur la piece.
        numero_lu: null, montant_lu: null, date_lue: null,
        immatriculation_lue: null,
        signe: null, tampon: null,
        ligne_batterie: null, prix_batterie: null, prix_batterie_ht: null,
        prix_batterie_ttc: null, mention_devise: null,
        adresse_facturation: null,
      };

      if ('PVL' === type) {
        piece.date_declaree = source.date;
        piece.date_lue = source.date;
        piece.signe = true;
        piece.tampon = grille.tampon ? true : null;
        piece.immatriculation_lue = dossier.immatriculation;
        if (anomaliesVoulues.has('PVL_NON_DATE_NON_SIGNE')) { piece.date_lue = null; piece.signe = false; }
        if (anomaliesVoulues.has('PVL_NON_TAMPONNE')) piece.tampon = false;
        if (anomaliesVoulues.has('PVL_IMMAT_DIVERGENTE')) {
          const autre = m.ref_vehicule[(i * 31 + 7) % m.ref_vehicule.length];
          piece.immatriculation_lue = autre.immatriculation;
        }
        if (anomaliesVoulues.has('DOSSIER_NON_DECLARE')) { piece.presente = false; }
      }

      if ('BDC' === type) {
        piece.numero_declare = numeroBdc;
        piece.numero_lu = numeroBdc;
        piece.montant_declare = source.montant;
        piece.montant_lu = source.montant;
        piece.date_lue = source.date;
        if (anomaliesVoulues.has('BDC_NUM_ILLISIBLE')) piece.numero_lu = null, piece.lisible = false;
        if (anomaliesVoulues.has('BDC_MONTANT_DIVERGENT')) {
          // L'ecart depasse franchement la tolerance du loueur.
          piece.montant_lu = Math.round((source.montant + tirage.montant(180, 2400)) * 100) / 100;
        }
        // L'ecart TOLERE : pose sur le bon de commande, parce que c'est LUI que
        // la regle confronte au montant facture. Pose ailleurs, il ne serait lu
        // par aucune regle et le scenario ne demontrerait rien.
        if ('SC-07-26' === scenario && grille.tolerance_centimes > 0) {
          piece.montant_lu = (montantCentimes + grille.tolerance_centimes) / 100;
        }
      }

      if ('CPI' === type) {
        piece.date_lue = source.date;
        piece.numero_lu = `CPI${String(200000 + (i * 4093) % 799999)}`;
        if (anomaliesVoulues.has('CPI_ABSENT')) piece.presente = false;
      }

      if ('F1' === type || 'F2' === type) {
        piece.numero_declare = dossier.numero_facture;
        piece.numero_lu = dossier.numero_facture;
        piece.montant_declare = source.montant;
        piece.montant_lu = source.montant;
        piece.date_lue = source.date;
        piece.adresse_facturation = 'loueur';
        // Le numero de commande, repris de la facture : c'est LUI que le
        // controle confronte au bon de commande.
        piece.numero_commande_lu = numeroBdc;
        if ('F1' === type && electrifie) {
          piece.ligne_batterie = true;
          const ht = Math.round(tirage.montant(4200, 11800) * 100) / 100;
          piece.prix_batterie = ht;
          piece.prix_batterie_ht = ht;
          piece.prix_batterie_ttc = Math.round(ht * 1.2 * 100) / 100;
          piece.mention_devise = true;
        }
        if ('F1' === type) {
          if (anomaliesVoulues.has('BDC_NUM_ABSENT')) piece.numero_commande_lu = null;
          if (anomaliesVoulues.has('BDC_NUM_DIVERGENT')) piece.numero_commande_lu = `BC${String(9100000 + i % 899999)}`;
          if (anomaliesVoulues.has('BAT_PRIX_ABSENT') && electrifie) {
            piece.prix_batterie = null; piece.prix_batterie_ht = null; piece.prix_batterie_ttc = null;
          }
          if (anomaliesVoulues.has('BAT_HT_MANQUANT') && electrifie) piece.prix_batterie_ht = null;
          if (anomaliesVoulues.has('BAT_TTC_MANQUANT') && electrifie) piece.prix_batterie_ttc = null;
          if (anomaliesVoulues.has('BAT_MENTION_MANQUANTE') && electrifie) piece.mention_devise = false;
          if (anomaliesVoulues.has('FACT_ADRESSE_CLIENT')) piece.adresse_facturation = 'client';
        }

      }

      if ('CG' === type) {
        piece.numero_lu = `CG${String(500000 + (i * 6151) % 499999)}`;
        piece.immatriculation_lue = dossier.immatriculation;
        piece.date_lue = source.date;
      }

      if ('RIB' === type) {
        // IBAN francais complet et VALIDE : 27 caracteres, banque 99999 non
        // attribuee, vraie cle RIB, MOD 97 = 1. Deterministe par le rang de la
        // piece, donc sans consommer de tirage : la suite du monde ne bouge pas.
        piece.numero_lu = ibanDeterministe(7000000 + i);
      }

      // Une piece que la grille ne demande pas mais qui manque : le scenario
      // qui verifie qu'on ne la reclame pas.
      if ('SC-07-25' === scenario && 'optionnelle' === exigence) piece.presente = false;

      m.ops_piece_gc.push(piece);
    }

    // --------------------------------------------------------------- la verite
    //
    // Le filtre d'applicabilite a deja ecarte les scenarios impossibles ; il
    // reste a le redire ici, parce que la verite ne doit jamais dependre de
    // l'ordre dans lequel on l'a construite.
    const retenues = [...anomaliesVoulues].filter(possible);

    // Le verdict se calcule sur les PIECES, pas sur la liste des codes.
    //
    //   conforme      aucune anomalie, et rien qui manque
    //   non_conforme  au moins une anomalie etablie sur des pieces lisibles
    //   incomplet     une piece OBLIGATOIRE manque, ou une valeur est
    //                 illisible : le doute ne se tranche pas, il se leve
    //
    // Une piece seulement optionnelle absente ne bloque rien -- c'est la
    // grille du payeur qui commande, et la reclamer serait la faute que
    // l'outil corrige.
    const miennes = m.ops_piece_gc.filter((x) => x.dossier_id === dossier.id);
    const manquante = miennes.some((x) => 'obligatoire' === x.exigence && !x.presente);
    const illisible = miennes.some((x) => !x.lisible);
    const verdict = (manquante || illisible) ? 'incomplet'
      : (retenues.length > 0 ? 'non_conforme' : 'conforme');

    verites.push({
      objet_id: dossier.id,
      verdict_attendu: verdict,
      nb_anomalies: retenues.length,
      anomalies_attendues: retenues.sort().join('|'),
      code_scenario: scenario,
      cohorte,
      exigences_du_loueur: attendues.map(([t]) => t).join('|'),
    });
    for (const code of retenues) {
      const def2 = ANOMALIES.find(([c]) => c === code);
      veritesAnomalies.push({
        objet_id: dossier.id,
        code_anomalie: code,
        type_piece: def2[1],
        gravite: def2[2],
        suite: def2[4],
        // Etablissable a partir des pieces PRESENTES ? Une anomalie que le
        // dossier ne permet pas de prouver ne rend pas le dossier non
        // conforme : elle le rend incomplet.
        etablie: !illisible && !manquante,
      });
    }
  });

  return {
    dossiers: m.ops_dossier_gc.length,
    pieces: m.ops_piece_gc.length,
    exigences: m.ref_exigence_piece.length,
    anomalies_plantees: veritesAnomalies.length,
    verites,
    veritesAnomalies,
    total,
  };
}
