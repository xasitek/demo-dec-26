// Construction du monde synthetique. GROUPE SYNTHAUTO.
// Ce module n'ecrit JAMAIS la verite de reference : voir fabrique/verite.js.

// Renomme a l'import : `compositions` est deja, dans ce module, le registre
// des compositions attendues qui part vers la fabrique de verite.
import { compositions as compositionsExactes } from './contrats.js';
import { planterLettrage } from './lettrage.js';
import { planterPilotage } from './pilotage.js';
import { planterGrandsComptes } from './grands-comptes.js';
import {
  generateur, nomSociete, nomPersonne, nomEntreprise, motInvente, tronqueBanque,
  numeroSerie, immatriculation, iban, empreinte, jourAleatoire, ajouterJours, iso,
  DEBUT, FIN, GRAINE, GRAINE_BLIND_2, GRAINE_BLIND_3, GRAINE_BLIND_4,
} from './aleatoire.js';

export const VOLUMES = {
  societes: 18, concessions: 36, etablissements: 54, marques: 11, banques: 5,
  loueurs: 8, financeurs: 6, utilisateurs: 140, clients: 14200, vehicules: 31800,
  buyback: 9400, factures: 62000, ecritures: 148000, virements: 24500,
  ordresReparation: 27000, dossiersLivraison: 3400, dossiersRemboursement: 2500,
  decisionsComite: 9800, lignesRelance: 12400,
  // Population aveugle nº 2, tiree d'une autre graine, mesuree une seule fois.
  virements_blind2: 3000,
  // Population aveugle finale, tiree apres le gel complet.
  virements_blind3: 3000,
  // Test final, tire apres correction du defaut revele par BLIND_TEST_3.
  virements_blind4: 6000,
};

const SERVICES = ['VN', 'VO', 'APV', 'CARR'];
const GREC = ['ALPHA', 'BETA', 'GAMMA', 'DELTA', 'EPSILON', 'ZETA', 'ETA', 'THETA'];
const ROMAIN = ['I', 'II', 'III', 'IV', 'V', 'VI'];

// Types de piece, repris du principe de grille documentaire par payeur.
export const PIECES_LIVRAISON = ['facture', 'pv_livraison', 'bon_commande', 'certificat_conformite',
  'carte_grise', 'mandat', 'attestation_batterie', 'proces_verbal_electronique'];

// Les 42 motifs de comite, en six familles.
export const MOTIFS_COMITE = [
  ['Premier loyer ou carte grise manquant', 'paiement'], ['A lettrer', 'paiement'],
  ['Carte bancaire manquante', 'paiement'], ['Dossier de financement incomplet', 'paiement'],
  ['Financement en cours', 'paiement'], ['Mouvement bancaire a affecter', 'paiement'],
  ['Paiement en cours', 'paiement'], ['Paiement en plusieurs fois', 'paiement'],
  ['Prelevement en cours', 'paiement'],
  ['A relancer', 'action'], ['Relance en cours', 'action'], ['Remboursement a faire', 'action'],
  ['Revoir le dossier', 'action'], ['Suivi juridique', 'action'],
  ['Avoir manquant', 'facturation'], ['Bonus ecologique manquant', 'facturation'],
  ['Caisse mal saisie', 'facturation'], ['Caisse pas saisie', 'facturation'],
  ["Erreur d'acompte", 'facturation'], ["Erreur d'affectation", 'facturation'],
  ['Erreur de montant sur la reprise', 'facturation'], ['Facture a annuler', 'facturation'],
  ['Facture a corriger', 'facturation'], ['Facture en litige', 'facturation'],
  ['Facture manquante', 'facturation'], ['Facture non transmise', 'facturation'],
  ['Reprise a corriger', 'facturation'], ['Reprise manquante', 'facturation'],
  ['Erreur logistique', 'livraison'], ['Vehicule non livre', 'livraison'],
  ['Ecart de reglement', 'resultat'], ['Perte', 'resultat'], ['Profit', 'resultat'],
  ['Solde a conserver', 'resultat'],
  ['Attente de retour client', 'divers'], ['Ancien collaborateur', 'divers'],
  ['Collaborateur', 'divers'], ['Divers, a preciser', 'divers'],
  ['Dossier incomplet', 'divers'], ['Litige client', 'divers'],
  ['Transfert intragroupe', 'divers'], ['Garantie de perte financiere', 'divers'],
];

export const FAMILLES_COMITE = {
  paiement: 'Suivi des paiements et lettrage', action: 'Actions a entreprendre',
  facturation: 'Facturation et comptabilite', livraison: 'Livraison et gestion des vehicules',
  resultat: 'Resultats financiers', divers: 'Justifications diverses',
};

const id = (prefixe, n, largeur) => `${prefixe}-${String(n).padStart(largeur, '0')}`;

export function construireMonde() {
  const rnd = generateur(GRAINE);
  const m = { meta: { graine: GRAINE, genere_le: new Date().toISOString(), groupe: 'GROUPE SYNTHAUTO', debut: iso(new Date(DEBUT)), fin: iso(new Date(FIN)) } };

  // ------------------------------------------------------------ referentiel
  m.ref_marque = Array.from({ length: VOLUMES.marques }, (_, i) => ({
    id: id('MRQ', i + 1, 2), code: `M${String(i + 1).padStart(2, '0')}`,
    nom: `MARQUE_${String(i + 1).padStart(2, '0')}`,
    segment: i < 2 ? 'premium' : i === 2 ? 'utilitaire' : i === 3 ? 'electrique' : 'generaliste',
  }));

  m.ref_banque = Array.from({ length: VOLUMES.banques }, (_, i) => ({
    id: id('BNQ', i + 1, 1), nom: `BANQUE ${i + 1}`,
    format_libelle: ['VIR SEPA {nom}', 'VIREMENT {nom}', '{nom} VIR RECU', 'VIR {nom} REF {ref}', 'SEPA CT {nom}'][i],
  }));

  m.ref_loueur = GREC.map((g, i) => ({
    id: id('LOU', i + 1, 2), nom: `LOUEUR ${g}`,
    // Chaque loueur exige des pieces differentes : c'est le coeur de l'outil 7.
    grille: [
      'facture', 'pv_livraison',
      ...(i % 2 === 0 ? ['certificat_conformite'] : ['bon_commande']),
      ...(i % 3 === 0 ? ['mandat'] : []),
      ...(i % 4 === 0 ? ['carte_grise'] : []),
    ],
    exige_batterie_ve: i % 3 !== 1,
    accepte_pv_electronique: i % 2 === 0,
    delai_paiement: [30, 45, 60][i % 3],
  }));

  m.ref_financeur = ROMAIN.map((r, i) => ({
    id: id('FIN', i + 1, 2), nom: `FINANCEUR ${r}`,
    type: i < 2 ? 'captive' : 'independant',
    marque_id: i < 2 ? m.ref_marque[i].id : null,
  }));

  m.ref_societe = Array.from({ length: VOLUMES.societes }, (_, i) => ({
    id: id('SOC', i + 1, 2), nom: nomSociete(rnd), forme: rnd.parmi(['SAS', 'SARL', 'SA']),
    facture_pour: null,
  }));
  // Trois societes facturent pour le compte d'une autre : matiere de l'inter-societe.
  for (let k = 0; k < 3; k++) m.ref_societe[15 + k].facture_pour = m.ref_societe[k].id;

  const DEPTS = ['67', '68', '54', '57', '51'];
  m.ref_concession = Array.from({ length: VOLUMES.concessions }, (_, i) => ({
    id: id('CNC', i + 1, 3), nom: `${motInvente(rnd, 2).toUpperCase()} ${DEPTS[i % 5]}`,
    societe_id: m.ref_societe[i % VOLUMES.societes].id,
    marque_id: m.ref_marque[i % VOLUMES.marques].id, departement: DEPTS[i % 5],
  }));

  m.ref_iban = [];
  const ibanParId = new Map();
  const ajouterIban = (titulaire) => {
    const v = iban(rnd);
    const o = { id: id('IBN', m.ref_iban.length + 1, 6), iban: v, empreinte: empreinte(v), titulaire, occurrences: 0, premiere_apparition: null };
    m.ref_iban.push(o); ibanParId.set(o.id, o); return o;
  };
  const trouverIban = (idIban) => ibanParId.get(idIban);

  m.ref_etablissement = [];
  for (let i = 0; i < VOLUMES.etablissements; i++) {
    const c = m.ref_concession[i % VOLUMES.concessions];
    const ib = ajouterIban(null);
    m.ref_etablissement.push({
      id: id('ETB', i + 1, 3), code: String(i + 1).padStart(3, '0'),
      nom: `${c.nom} ${SERVICES[i % 4]}`, concession_id: c.id, societe_id: c.societe_id,
      service: SERVICES[i % 4], iban_id: ib.id, actif: i !== 51,
    });
  }

  const ROLES = [['secretaire', 0.5], ['comptable', 0.3], ['directeur', 0.2]];
  m.ref_utilisateur = Array.from({ length: VOLUMES.utilisateurs }, (_, i) => {
    const role = i < 3 ? ['secretaire', 'comptable', 'directeur'][i] : rnd.poids(ROLES);
    const nb = role === 'directeur' ? rnd.entier(1, 4) : rnd.entier(1, 2);
    const etabs = Array.from({ length: nb }, () => rnd.parmi(m.ref_etablissement).id);
    return { id: id('USR', i + 1, 4), nom: nomPersonne(rnd), role, etablissements: [...new Set(etabs)] };
  });

  // Tout etablissement doit avoir un directeur, sinon le controle de perimetre
  // se declencherait par accident et non par scenario. C'est une exigence de
  // coherence interne : une regle ne doit jamais mordre pour la mauvaise raison.
  {
    const directeurs = m.ref_utilisateur.filter((u) => u.role === 'directeur');
    const couverts = new Set(directeurs.flatMap((u) => u.etablissements));
    let k = 0;
    for (const e of m.ref_etablissement) {
      if (!couverts.has(e.id)) {
        directeurs[k % directeurs.length].etablissements.push(e.id);
        k += 1;
      }
    }
  }

  // ------------------------------------------------------------ clients
  const TYPES_CLIENT = [['particulier', 0.55], ['professionnel', 0.28], ['loueur', 0.09], ['financeur', 0.05], ['administration', 0.03]];
  m.ref_client = [];
  for (let i = 0; i < VOLUMES.clients; i++) {
    const type = rnd.poids(TYPES_CLIENT);
    const nom = type === 'particulier' ? nomPersonne(rnd)
      : type === 'loueur' ? rnd.parmi(m.ref_loueur).nom
        : type === 'financeur' ? rnd.parmi(m.ref_financeur).nom
          : nomEntreprise(rnd);
    const code = String(100000 + i);
    const c = {
      id: id('CLI', i + 1, 7), nom, type,
      // 30 % des comptes portent deux codes divergents : le fait sectoriel a reproduire.
      code_referentiel: rnd.chance(0.3) ? `C${code}` : code,
      code_balance: code,
      etablissement_id: rnd.parmi(m.ref_etablissement).id,
      iban_id: null, rythme: rnd.parmi(['ponctuel', 'mensuel', 'irregulier']),
      // Neuf chiffres commencant par 0 : aucun identifiant reel ne commence par 0.
      siren: type === 'particulier' ? null : '0' + String(rnd.entier(10000000, 99999999)),
    };
    const ib = ajouterIban(c.id); c.iban_id = ib.id;
    m.ref_client.push(c);
  }
  // ---- Comptes clients partageant un meme compte bancaire.
  //
  // C'est un fait sectoriel, pas une commodite de demonstration : une meme
  // enseigne porte plusieurs codes clients -- un par etablissement, ou un
  // ancien et un nouveau -- et regle depuis un compte unique. L'IBAN identifie
  // alors le PAYEUR, jamais le compte a mouvementer. Sans ces groupes, l'outil
  // n'aurait aucune occasion de montrer qu'il sait s'arreter.
  const groupesIban = [];
  for (let i = 1; i < m.ref_client.length; i++) {
    if (!rnd.chance(0.045)) continue;
    const aine = m.ref_client[i - 1];
    if (aine._partage) continue;
    const cadet = m.ref_client[i];
    // Meme compte bancaire ET meme raison sociale : deux codes de la meme maison.
    cadet.iban_id = aine.iban_id;
    cadet.nom = aine.nom;
    cadet.type = aine.type;
    aine._partage = true; cadet._partage = true;
    groupesIban.push([aine, cadet]);
  }
  for (const c of m.ref_client) delete c._partage;

  // Le loueur du cas vedette est mis de cote une fois le cas plante : aucun
  // autre virement ne doit venir rouvrir des creances a son nom.
  let reserveLoueurVedette = null;
  const clientsLoueur = m.ref_client.filter((c) => c.type === 'loueur');
  const clientsFinanceur = m.ref_client.filter((c) => c.type === 'financeur');

  // ------------------------------------------------------------ vehicules
  const ENERGIES = [['essence', 0.38], ['diesel', 0.3], ['hybride', 0.19], ['electrique', 0.13]];
  m.ref_vehicule = Array.from({ length: VOLUMES.vehicules }, (_, i) => {
    const marque = rnd.parmi(m.ref_marque);
    return {
      id: id('VEH', i + 1, 7), vin: numeroSerie(rnd), immatriculation: immatriculation(rnd),
      marque_id: marque.id, modele: `${marque.nom} ${motInvente(rnd, 1).toUpperCase()}`,
      energie: marque.segment === 'electrique' ? 'electrique' : rnd.poids(ENERGIES),
      client_id: m.ref_client[rnd.entier(0, VOLUMES.clients - 1)].id,
    };
  });

  m.ref_contrat_buyback = Array.from({ length: VOLUMES.buyback }, (_, i) => {
    const v = m.ref_vehicule[i * 3 % VOLUMES.vehicules];
    const ht = rnd.montant(6500, 42000, 10);
    return {
      id: id('BBK', i + 1, 7), vehicule_id: v.id, vin: v.vin, immatriculation: v.immatriculation,
      financeur_id: rnd.parmi(m.ref_financeur).id,
      er_ht: Math.round(ht * 100) / 100, er_ttc: Math.round(ht * 1.2 * 100) / 100,
      echeance: jourAleatoire(rnd, Date.UTC(2026, 0, 1), Date.UTC(2027, 5, 1)),
      km_contrat: rnd.entier(30000, 150000) - (rnd.entier(30000, 150000) % 5000),
      statut: rnd.poids([['actif', 0.82], ['echu', 0.18]]),
    };
  });

  // ------------------------------------------------------------ factures
  const TYPES_FACTURE = [['VN', 0.24], ['VO', 0.18], ['APV', 0.38], ['CARR', 0.12], ['GAR', 0.08]];
  const SOLDE_PAR_TYPE = {
    VN: [['soldee', 0.965], ['ouverte', 0.028], ['partielle', 0.007]],
    VO: [['soldee', 0.945], ['ouverte', 0.046], ['partielle', 0.009]],
    APV: [['soldee', 0.80], ['ouverte', 0.175], ['partielle', 0.025]],
    CARR: [['soldee', 0.82], ['ouverte', 0.155], ['partielle', 0.025]],
    GAR: [['soldee', 0.60], ['ouverte', 0.36], ['partielle', 0.04]],
  };
  m.ops_facture = [];
  for (let i = 0; i < VOLUMES.factures; i++) {
    const etab = m.ref_etablissement[i % VOLUMES.etablissements];
    const type = rnd.poids(TYPES_FACTURE);
    const veh = m.ref_vehicule[rnd.entier(0, VOLUMES.vehicules - 1)];
    const client = type === 'VN' && rnd.chance(0.22) ? rnd.parmi(clientsLoueur)
      : m.ref_client[rnd.entier(0, VOLUMES.clients - 1)];
    const date = jourAleatoire(rnd);
    const montant = type === 'VN' ? rnd.montant(14000, 68000)
      : type === 'VO' ? rnd.montant(4500, 38000)
        : type === 'CARR' ? rnd.montant(400, 7800) : rnd.montant(58, 2400);
    // Le vehicule facture appartient au client facture. Sans cette coherence,
    // toute concordance de numero de serie serait fausse par construction.
    veh.client_id = client.id;

    m.ops_facture.push({
      id: id('FAC', i + 1, 7), numero: `F${date.slice(0, 4)}${String(i + 1).padStart(7, '0')}`,
      client_id: client.id, societe_id: etab.societe_id, etablissement_id: etab.id,
      vehicule_id: veh.id, type, date, echeance: ajouterJours(date, rnd.parmi([0, 30, 45, 60])),
      montant: Math.round(montant * 100) / 100,
      // Le statut depend du type : un vehicule neuf finance se solde vite, une
      // creance de garantie constructeur traine. C'est ce qui donne un encours
      // credible, et c'est le fait sectoriel a reproduire.
      statut: rnd.poids(SOLDE_PAR_TYPE[type]),
    });
  }

  // ------------------------------------------------------------ ecritures
  // Modele comptable, et non un tirage : un debit par facture, un ou deux
  // credits quand elle est soldee, un credit partiel quand elle l'est en partie,
  // puis des CREDITS NON AFFECTES qui sont la matiere des outils 4 et 5.
  const vehiculeParId = new Map(m.ref_vehicule.map((v) => [v.id, v]));
  const JOURNAUX_BANQUE = ['BQ', 'BQ', 'BQ', 'CA'];
  m.ops_ecriture = [];
  let compteurLettrage = 0;

  const pousser = (o) => { m.ops_ecriture.push({ id: id('ECR', m.ops_ecriture.length + 1, 8), ...o }); };

  const clesSectorielles = (f, veh) => ({
    // Les cles ne sont pas toujours renseignees : c'est precisement la difficulte.
    vin: rnd.chance(0.62) ? veh.vin : null,
    immatriculation: rnd.chance(0.48) ? veh.immatriculation : null,
    ordre_reparation: f.type === 'APV' && rnd.chance(0.7)
      ? id('ORD', rnd.entier(1, VOLUMES.ordresReparation), 7) : null,
    reference_piece: rnd.chance(0.59) ? f.numero : null,
  });

  for (const f of m.ops_facture) {
    const veh = vehiculeParId.get(f.vehicule_id) || m.ref_vehicule[0];
    const lettre = f.statut === 'soldee' ? id('LET', ++compteurLettrage, 6) : null;
    pousser({
      facture_id: f.id, societe_id: f.societe_id, etablissement_id: f.etablissement_id,
      client_id: f.client_id, compte: '4111000', journal: 'VE', sens: 'D',
      montant: f.montant, date: f.date, ...clesSectorielles(f, veh), lettrage: lettre,
    });
    if (f.statut === 'soldee') {
      const enDeux = rnd.chance(0.16);
      const parts = enDeux ? [rnd.montant(0.3, 0.7)] : [1];
      if (enDeux) parts.push(1 - parts[0]);
      let reste = f.montant;
      parts.forEach((q, k) => {
        const montant = k === parts.length - 1 ? Math.round(reste * 100) / 100 : Math.round(f.montant * q * 100) / 100;
        reste -= montant;
        pousser({
          facture_id: f.id, societe_id: f.societe_id, etablissement_id: f.etablissement_id,
          client_id: f.client_id, compte: '5120200', journal: rnd.parmi(JOURNAUX_BANQUE), sens: 'C',
          montant, date: ajouterJours(f.date, rnd.entier(1, 62)), ...clesSectorielles(f, veh),
          lettrage: lettre,
        });
      });
    } else if (f.statut === 'partielle') {
      pousser({
        facture_id: f.id, societe_id: f.societe_id, etablissement_id: f.etablissement_id,
        client_id: f.client_id, compte: '5120200', journal: rnd.parmi(JOURNAUX_BANQUE), sens: 'C',
        montant: Math.round(f.montant * rnd.montant(0.2, 0.8) * 100) / 100,
        date: ajouterJours(f.date, rnd.entier(3, 90)), ...clesSectorielles(f, veh), lettrage: null,
      });
    }
  }

  // Les credits non affectes : acomptes dormants, reglements sans reference,
  // trop-percus, virements de financeurs non imputes. Ils portent le probleme.
  const restant = VOLUMES.ecritures - m.ops_ecriture.length;
  for (let i = 0; i < restant; i++) {
    const client = m.ref_client[rnd.entier(0, VOLUMES.clients - 1)];
    const etab = m.ref_etablissement[rnd.entier(0, VOLUMES.etablissements - 1)];
    const veh = m.ref_vehicule[rnd.entier(0, VOLUMES.vehicules - 1)];
    pousser({
      facture_id: null, societe_id: etab.societe_id, etablissement_id: etab.id,
      client_id: client.id,
      compte: rnd.poids([['4111000', 0.72], ['4012000', 0.18], ['4116000', 0.10]]),
      journal: rnd.parmi(JOURNAUX_BANQUE), sens: 'C',
      montant: Math.round(rnd.montant(35, 4200) * 100) / 100,
      date: jourAleatoire(rnd),
      vin: rnd.chance(0.21) ? veh.vin : null,
      immatriculation: rnd.chance(0.17) ? veh.immatriculation : null,
      ordre_reparation: null,
      reference_piece: rnd.chance(0.12) ? `REF${rnd.entier(100000, 999999)}` : null,
      lettrage: null,
    });
  }

  // ------------------------------------------------------------ ordres de reparation
  m.ops_ordre_reparation = Array.from({ length: VOLUMES.ordresReparation }, (_, i) => {
    const v = m.ref_vehicule[rnd.entier(0, VOLUMES.vehicules - 1)];
    return {
      id: id('ORD', i + 1, 7), vehicule_id: v.id, vin: v.vin, immatriculation: v.immatriculation,
      etablissement_id: rnd.parmi(m.ref_etablissement).id, date: jourAleatoire(rnd),
      montant: Math.round(rnd.montant(58, 3200) * 100) / 100,
    };
  });

  // ------------------------------------------------------------ virements
  // Scenario tire d'abord, donnee fabriquee ensuite : methode a rebours.
  const SCEN_VIR = [
    ['SC-04-01', 0.30], ['SC-04-02', 0.13], ['SC-04-03', 0.09], ['SC-04-04', 0.08],
    ['SC-04-05', 0.03], ['SC-04-06', 0.05], ['SC-04-07', 0.06], ['SC-04-08', 0.07],
    ['SC-04-09', 0.03], ['SC-04-10', 0.04], ['SC-04-11', 0.05], ['SC-04-12', 0.03],
    // Deux classes adverses de plus, pour que chaque regle d'arret ait sa
    // population : plusieurs compositions exactes, et un numero de serie du
    // libelle qui appartient a quelqu'un d'autre.
    ['SC-04-13', 0.02], ['SC-04-14', 0.02],
  ];

  /** Les scenarios qu'aucun outil n'a le droit d'affecter seul. */
  const SCEN_HUMAIN = ['SC-04-10', 'SC-04-11', 'SC-04-13', 'SC-04-14'];
  const facturesOuvertes = m.ops_facture.filter((f) => f.statut !== 'soldee');
  // Toutes les factures, indexees par client, et un drapeau d'affectation.
  //
  // Le modele est celui du reel : un virement regle des factures de SON payeur,
  // et ces factures restent NON AFFECTEES tant que personne ne les a rapprochees.
  // Les autres sont reglees autrement -- carte, especes, financement -- et sont
  // marquees affectees d'emblee. C'est ce vivier de non-affectees que le moteur
  // examine, et c'est ce qui rend le rapprochement possible.
  for (const f of m.ops_facture) f.affectee = true;

  const parClient = new Map();
  for (const f of m.ops_facture) {
    if (f.vedette) continue;
    const l = parClient.get(f.client_id) || [];
    l.push(f); parClient.set(f.client_id, l);
  }
  const reserve = m.ops_facture.filter((f) => !f.vedette);
  let curseurReserve = 0;
  // Le vivier reellement ouvert, tenu au fil de la fabrique. Il sert a garantir
  // qu'un cas annonce insoluble le reste : sur soixante mille factures, un
  // montant decale au hasard finit par tomber juste par coincidence.
  const ouvertesParClient = new Map();

  /** Prend n factures du payeur ; en rattache si son portefeuille est trop mince. */
  const lotDuPayeur = (payeur, n) => {
    const pool = parClient.get(payeur.id) || [];
    const lot = [];
    // Une facture deja prise par un autre virement ne peut pas etre reprise :
    // sinon deux virements pretendraient regler la meme creance.
    // Le drapeau se pose A LA PRISE, pas a la fin du lot. Sinon une facture
    // deja retenue par le portefeuille du payeur restait libre aux yeux de la
    // reserve, et le meme lot pouvait la compter deux fois : le virement
    // s'expliquait alors par une facture reglee deux fois, ce qu'aucun vivier
    // reel ne permet. Le contrat de scenario l'a vu, un cas sur deux mille.
    while (lot.length < n && pool.length > 0) {
      const f = pool.pop();
      if (!f._prise) { f._prise = true; lot.push(f); }
    }
    while (lot.length < n && curseurReserve < reserve.length) {
      const f = reserve[curseurReserve++];
      if (f._prise) continue;
      f._prise = true;
      f.client_id = payeur.id;
      // Le vehicule suit la facture. Sans cela, on fabriquerait une
      // contradiction qui n'existe pas dans le reel : un payeur reglant une
      // facture portant le vehicule de quelqu'un d'autre.
      const veh = vehiculeParId.get(f.vehicule_id);
      if (veh) veh.client_id = payeur.id;
      lot.push(f);
    }
    for (const f of lot) {
      f._prise = true; f.affectee = false;
      if (!ouvertesParClient.has(f.client_id)) ouvertesParClient.set(f.client_id, []);
      ouvertesParClient.get(f.client_id).push(f);
    }
    return lot;
  };

  m.ops_virement = [];
  // Compositions attendues : transmises a la fabrique de verite, jamais au monde.
  const compositions = new Map();
  const scenarios = new Map();
  // Le vrai payeur, tel que la fabrique l'a choisi. C'est LUI la verite, pas
  // une reconstitution posterieure a partir de l'empreinte d'IBAN.
  const payeurs = new Map();
  // Le cas est-il reellement ambigu, jumeau arme compris ?
  const ambigus = new Map();

  // ---- CAS VEDETTE, plante volontairement : TX-000001.
  // Un virement global d'un loueur, compose de SIX factures ouvertes relevant de
  // TROIS societes differentes, libelle tronque, aucune reference exploitable.
  // C'est le point de depart de la visite guidee et du parcours transverse.
  {
    // Les six montants sont posés, et leur somme fait exactement 126 843 €.
    const MONTANTS = [18200, 27418, 16790, 21650, 19385, 23400];
    const loueur = clientsLoueur[0];
    // Trois sociétés, deux factures chacune, six véhicules distincts.
    const societes = [m.ref_societe[0], m.ref_societe[5], m.ref_societe[11]];
    const lot = [];
    for (let k = 0; k < 6; k++) {
      const soc = societes[Math.floor(k / 2)];
      const etab = m.ref_etablissement.find((e) => e.societe_id === soc.id) || m.ref_etablissement[k];
      const f = m.ops_facture[1000 + k * 137];
      const veh = m.ref_vehicule[4000 + k * 311];
      f.client_id = loueur.id;
      f.societe_id = soc.id;
      f.etablissement_id = etab.id;
      f.vehicule_id = veh.id;
      f.type = 'VN';
      f.montant = MONTANTS[k];
      f.statut = 'ouverte';
      f.date = ajouterJours('2026-07-01', k * 3);
      f.echeance = ajouterJours(f.date, 45);
      lot.push(f);
      // Le véhicule appartient bien au loueur : c'est ce qui rend la
      // concordance des numéros de série vérifiable par le moteur.
      veh.client_id = loueur.id;
      f.vedette = true;
      f.affectee = false;
      // Reservee des maintenant : la reserve de rattachement a ete construite
      // AVANT ce bloc, donc sans ce drapeau un virement ulterieur reprendrait
      // ces six factures et les rattacherait a un autre payeur. Le cas vedette
      // se defaisait alors tout seul, sans que rien ne le signale.
      f._prise = true;
    }
    const ibanVedette = trouverIban(loueur.iban_id);
    ibanVedette.occurrences += 31;
    ibanVedette.premiere_apparition = '2025-04-14';
    const montant = Math.round(lot.reduce((s, f) => s + f.montant, 0) * 100) / 100;
    m.ops_virement.push({
      id: 'TX-000001', date: '2026-08-31', montant,
      banque_id: m.ref_banque[3].id, iban_emetteur_id: ibanVedette.id,
      libelle: tronqueBanque(`VIR ${loueur.nom} REF BORD2026000874`),
      nom_donneur_ordre: tronqueBanque(loueur.nom),
      reference_bout_en_bout: 'E2E-20260831-BORD2026000874',
      societe_id: lot[0].societe_id, sens_mixte: false, vedette: true,
      // Population de VALIDATION : les poids ne sont calibrés que sur
      // CALIBRATION, donc ce cas vedette n'a jamais servi à régler le moteur.
      cohorte: 'VALIDATION',
    });
    scenarios.set('TX-000001', 'SC-04-07');
    payeurs.set('TX-000001', loueur.id);
    ambigus.set('TX-000001', false);

    // Le cas vedette doit avoir UNE seule explication. Les autres creances de
    // ce loueur sont donc reglees et lettrees : il ne reste ouvertes que les
    // six factures du bordereau. Sans cette hygiene, un autre sous-ensemble du
    // portefeuille tombait juste sur le meme total, et l'outil -- a raison --
    // renvoyait le choix au comptable au lieu d'affecter seul.
    for (const f of m.ops_facture) {
      if (f.client_id === loueur.id && !f.vedette) { f.affectee = true; f._prise = true; }
    }
    reserveLoueurVedette = loueur.id;
    // La composition attendue est une REPONSE : elle part dans la verite.
    compositions.set('TX-000001', lot.map((f) => f.id));
  }

  // Le motif de reference propre a un payeur : beaucoup de donneurs d'ordre
  // portent dans la reference de bout en bout un motif stable, propre a leur
  // maison. C'est exactement ce qu'un contrat de donnees enrichi permet
  // d'exploiter, et cela ne coute rien au client : il l'emet deja.
  const motifDe = (c) => `RF${c.code_balance}`;

  /** Un virement, quel que soit le tirage aleatoire qui le produit. */
  const fabriquerVirement = (tirage, i, cohorte) => {
    let scenario = tirage.poids(SCEN_VIR);
    const banque = tirage.parmi(m.ref_banque);
    let payeur = m.ref_client[tirage.entier(0, VOLUMES.clients - 1)];
    if (scenario === 'SC-04-06') payeur = tirage.parmi(clientsFinanceur);
    if (scenario === 'SC-04-07') payeur = tirage.parmi(clientsLoueur);
    if (payeur.id === reserveLoueurVedette) payeur = tirage.parmi(clientsLoueur.filter((c) => c.id !== reserveLoueurVedette));

    // Les deux scenarios d'ambiguite ne se decretent pas dans la verite : ils
    // se CONSTRUISENT dans le monde. Un payeur dont le compte bancaire est
    // partage avec un autre code client, et rien pour departager.
    let jumeau = null;
    if ((scenario === 'SC-04-09' || scenario === 'SC-04-10') && groupesIban.length > 0) {
      const groupe = tirage.parmi(groupesIban);
      payeur = groupe[0]; jumeau = groupe[1];
    }

    let nbFactures = scenario === 'SC-04-07' || scenario === 'SC-04-08' ? tirage.entier(3, 6)
      : scenario === 'SC-04-13' ? 4 : 1;
    let lot = lotDuPayeur(payeur, nbFactures);

    // Le vivier n'est pas infini. Un scenario multi-factures dont la fabrique
    // n'a pas pu servir le lot ne devient pas un scenario multi-factures a une
    // seule facture : il redevient un reglement simple, et la verite le dit.
    // Le contrat de scenario aurait de toute facon refuse le contraire.
    if (nbFactures > 1 && lot.length < 2) {
      scenario = lot.length === 1 ? 'SC-04-01' : scenario;
      nbFactures = lot.length;
    }
    if (lot.length === 0) {
      lot = [m.ops_facture[i % VOLUMES.factures]];
      scenario = 'SC-04-01';
    }

    // Deux compositions exactes du MEME montant. On pose quatre factures
    // a+b = c+d, toutes de montants distincts : le payeur est certain, la
    // creance reglee ne l'est pas. C'est le piege comptable pur -- un compte
    // juste au total, faux ligne a ligne.
    let compositionMultiple = false;
    if (scenario === 'SC-04-13' && lot.length === 4) {
      const a = Math.round(tirage.montant(900, 9000) * 100) / 100;
      const b = Math.round(tirage.montant(900, 9000) * 100) / 100;
      const ecart = Math.round(tirage.montant(60, 400) * 100) / 100;
      if (b - ecart > 50 && ecart !== 0) {
        lot[0].montant = a; lot[1].montant = b;
        lot[2].montant = Math.round((a + ecart) * 100) / 100;
        lot[3].montant = Math.round((b - ecart) * 100) / 100;
        compositionMultiple = true;
      }
    }

    // La somme se fait EN CENTIMES, pas en euros arrondis a la fin. Additionner
    // des flottants puis arrondir le total peut le decaler d'un centime par
    // rapport a la somme des factures arrondies une a une -- et un centime
    // suffit a rendre le virement incomposable. Un cas sur deux mille tombait
    // ainsi, et le contrat de scenario l'a vu.
    const cent = (x) => Math.round(x * 100);
    const montant = scenario === 'SC-04-13' && compositionMultiple
      ? (cent(lot[0].montant) + cent(lot[1].montant)) / 100
      : lot.reduce((s, f) => s + cent(f.montant), 0) / 100;

    // Le jumeau porte une facture ouverte du MEME montant : les deux comptes
    // expliquent le virement aussi bien l'un que l'autre.
    //
    // Sur le cas volontairement ambigu, la symetrie doit etre COMPLETE. Il ne
    // suffit pas d'aligner le montant : si l'une des deux factures porte un
    // vehicule ou une societe habituelle et pas l'autre, le monde offre un
    // discriminant que la verite ignore, et le moteur tranche -- correctement
    // au regard des preuves, faussement au regard de la verite. On retire donc
    // des deux cotes tout ce qui pourrait departager.
    if (jumeau !== null) {
      const lotJumeau = lotDuPayeur(jumeau, 1);
      if (lotJumeau.length > 0) {
        lotJumeau[0].montant = montant;
        if (scenario === 'SC-04-10') {
          lotJumeau[0].societe_id = lot[0].societe_id;
          lotJumeau[0].etablissement_id = lot[0].etablissement_id;
          lotJumeau[0].vehicule_id = null;
          lot[0].vehicule_id = null;
        }
      } else {
        jumeau = null;
      }
    }

    const ibanEmetteur = trouverIban(payeur.iban_id);
    ibanEmetteur.occurrences += 1;

    // Un scenario qui annonce une reference dans le libelle doit tirer un
    // format de libelle qui en porte une. Sans cela le monde ne materialisait
    // la reference qu'une fois sur cinq, et la classe ne demontrait rien.
    // La reference passe AVANT le nom : la banque tronque a trente-cinq
    // caracteres, et une raison sociale longue emportait la reference avec
    // elle. Le scenario annoncait alors une reference que le libelle ne
    // portait pas. Beaucoup de banques placent d'ailleurs la reference en tete.
    const REF_DANS_LIBELLE = ['SC-04-02', 'SC-04-03', 'SC-04-09', 'SC-04-14'];
    const format = REF_DANS_LIBELLE.includes(scenario)
      ? 'VIR REF {ref} {nom}' : banque.format_libelle;

    // Un numero de serie cite au libelle qui appartient a un AUTRE compte
    // client : la contradiction dure, celle qui doit arreter net.
    let serieEtrangere = null;
    if (scenario === 'SC-04-14') {
      for (let essai = 0; essai < 40 && serieEtrangere === null; essai++) {
        const veh = tirage.parmi(m.ref_vehicule);
        if (veh.client_id && veh.client_id !== payeur.id) serieEtrangere = veh.vin.slice(-8);
      }
    }

    const nomOrdre = scenario === 'SC-04-04' || scenario === 'SC-04-05'
      ? nomPersonne(tirage) : payeur.nom;

    // Reference de bout en bout : le motif du payeur quand il en emet un.
    // Jamais sur le cas ambigu, sinon il cesse de l'etre.
    const e2e = scenario === 'SC-04-10' ? null
      : tirage.chance(0.63) ? `E2E-${jourAleatoire(tirage).replace(/-/g, '')}-${motifDe(payeur)}-${String(i).padStart(6, '0')}`
        : tirage.chance(0.4) ? `E2E-${jourAleatoire(tirage).replace(/-/g, '')}-${String(i).padStart(6, '0')}` : null;

    // Le numero de facture au libelle tranche le faux candidat plausible.
    const ref = scenario === 'SC-04-14' && serieEtrangere !== null ? serieEtrangere
      : scenario === 'SC-04-03' || scenario === 'SC-04-09' ? lot[0].numero
        : scenario === 'SC-04-02' ? `BORD${String(i).padStart(6, '0')}` : '';

    // Le cas insoluble le reste : on decale le montant de quelques euros pour
    // qu'aucune combinaison ne le compose jamais.
    let montantFinal = montant;
    if (scenario === 'SC-04-11') {
      // On decale, puis on VERIFIE. Tant qu'un sous-ensemble explique le
      // montant decale, le cas n'est pas insoluble et on decale a nouveau.
      const pool = (ouvertesParClient.get(payeur.id) || []).map((f) => f.montant);
      for (let essai = 0; essai < 10; essai++) {
        montantFinal = Math.round((montant + tirage.montant(17, 289)) * 100) / 100;
        if (compositionsExactes(pool, Math.round(montantFinal * 100)) === 0) break;
      }
    }

    const idVir = id('TX', i + 1, 6);
    m.ops_virement.push({
      id: idVir, date: jourAleatoire(tirage), montant: montantFinal,
      banque_id: banque.id, iban_emetteur_id: ibanEmetteur.id,
      libelle: tronqueBanque(format.replace('{nom}', nomOrdre).replace('{ref}', ref)),
      nom_donneur_ordre: tronqueBanque(nomOrdre),
      reference_bout_en_bout: e2e,
      societe_id: lot[0].societe_id, sens_mixte: scenario === 'SC-04-12',
      vedette: false, cohorte,
    });
    // Le montant decale n'est explique par aucune facture : la composition
    // attendue est vide, et c'est la bonne reponse.
    compositions.set(idVir, scenario === 'SC-04-11' ? []
      : scenario === 'SC-04-13' && compositionMultiple ? [lot[0].id, lot[1].id]
        : lot.map((f) => f.id));
    scenarios.set(idVir, scenario);
    payeurs.set(idVir, payeur.id);
    // Ce qui interdit l'affectation automatique, et pour quelle raison.
    // Un scenario adverse dont le piege n'a pas pu etre arme redevient un cas
    // ordinaire : on ne declare jamais une difficulte que le monde ne porte pas.
    const arme = scenario === 'SC-04-10' ? jumeau !== null
      : scenario === 'SC-04-13' ? compositionMultiple
        : scenario === 'SC-04-14' ? serieEtrangere !== null
          : scenario === 'SC-04-11';
    ambigus.set(idVir, SCEN_HUMAIN.includes(scenario) && arme);
  };

  for (let i = 1; i < VOLUMES.virements; i++) {
    // Trois populations disjointes. Les poids et les seuils ne sont calibres
    // que sur CALIBRATION. HOLDOUT_1 a servi a l'analyse des erreurs : il ne
    // peut plus servir de preuve.
    fabriquerVirement(rnd, i, rnd.poids([['CALIBRATION', 0.20], ['VALIDATION', 0.20], ['HOLDOUT_1', 0.60]]));
  }

  // ---- BLIND_TEST_2 : une population entierement nouvelle, tiree d'une autre
  // graine. Elle n'a servi a rien -- ni calibrage, ni analyse d'erreur -- et
  // c'est la seule dont les chiffres soient publiables comme resultat.
  const rnd2 = generateur(GRAINE_BLIND_2);
  for (let k = 0; k < VOLUMES.virements_blind2; k++) {
    fabriquerVirement(rnd2, VOLUMES.virements + k, 'BLIND_TEST_2');
  }

  // ---- BLIND_TEST_3 : la population du chiffre publie. Tiree apres le gel du
  // generateur, des contrats de scenario, des poids, des seuils, des regles
  // d'arret, du calcul de marge et de la logique BRUT/ENRICHI. Mesuree une
  // seule fois, jamais ouverte cas par cas avant d'avoir enregistre les KPI.
  const rnd3 = generateur(GRAINE_BLIND_3);
  for (let k = 0; k < VOLUMES.virements_blind3; k++) {
    fabriquerVirement(rnd3, VOLUMES.virements + VOLUMES.virements_blind2 + k, 'BLIND_TEST_3');
  }

  const rnd4 = generateur(GRAINE_BLIND_4);
  for (let k = 0; k < VOLUMES.virements_blind4; k++) {
    fabriquerVirement(rnd4, VOLUMES.virements + VOLUMES.virements_blind2 + VOLUMES.virements_blind3 + k, 'BLIND_TEST_4');
  }

  // ---- Insolubilite, verifiee une derniere fois quand le vivier est definitif.
  //
  // Le portefeuille ouvert d'un payeur grossit au fil de la fabrique : un
  // montant rendu inexplicable au moment ou on le pose peut redevenir
  // explicable quand une creance s'ajoute plus tard au meme compte. Le controle
  // doit donc repasser a la fin, sur le monde tel qu'il sera lu.
  for (const t of m.ops_virement) {
    if (scenarios.get(t.id) !== 'SC-04-11') continue;
    const pool = (ouvertesParClient.get(payeurs.get(t.id)) || []).map((f) => f.montant);
    for (let essai = 0; essai < 25; essai++) {
      if (compositionsExactes(pool, Math.round(t.montant * 100)) === 0) break;
      t.montant = Math.round((t.montant + rnd.montant(11, 97)) * 100) / 100;
    }
  }

  // ------------------------------------------------------------ dossiers de livraison
  const SCEN_DLV = [['SC-07-01', 0.62], ['SC-07-02', 0.09], ['SC-07-03', 0.07], ['SC-07-04', 0.06], ['SC-07-05', 0.06], ['SC-07-06', 0.10]];
  m.ops_dossier_livraison = []; m.ops_piece_livraison = [];
  // Une facture sans vehicule ne peut pas porter un dossier de livraison :
  // certaines factures de service, et les deux factures du cas volontairement
  // ambigu, n'en portent aucun.
  const facturesVN = m.ops_facture.filter((f) => f.type === 'VN' && vehiculeParId.has(f.vehicule_id));
  for (let i = 0; i < VOLUMES.dossiersLivraison; i++) {
    const scenario = rnd.poids(SCEN_DLV);
    const loueur = m.ref_loueur[i % VOLUMES.loueurs];
    const f = facturesVN[rnd.entier(0, facturesVN.length - 1)];
    const veh = vehiculeParId.get(f.vehicule_id);
    const d = {
      id: id('DLV', i + 1, 6), loueur_id: loueur.id, facture_id: f.id, vehicule_id: veh.id,
      etablissement_id: f.etablissement_id, montant: f.montant, date: f.date,
    };
    m.ops_dossier_livraison.push(d);
    scenarios.set(d.id, scenario);

    const attendues = [...loueur.grille];
    if (veh.energie === 'electrique' && loueur.exige_batterie_ve) attendues.push('attestation_batterie');
    for (const type of attendues) {
      let presente = true, lisible = true;
      if (scenario === 'SC-07-02' && type === 'attestation_batterie') presente = false;
      if (scenario === 'SC-07-04' && type === 'pv_livraison' && !loueur.accepte_pv_electronique) presente = false;
      if (scenario === 'SC-07-05' && type === 'facture') lisible = false;
      m.ops_piece_livraison.push({
        id: id('PLV', m.ops_piece_livraison.length + 1, 7), dossier_id: d.id, type, presente, lisible,
      });
    }
  }

  // ------------------------------------------------------------ remboursements
  const SCEN_RBC = [
    ['SC-08-01', 0.6624], ['SC-08-02', 0.012], ['SC-08-03', 0.014], ['SC-08-04', 0.018],
    ['SC-08-05', 0.014], ['SC-08-06', 0.022], ['SC-08-07', 0.026], ['SC-08-08', 0.014],
    ['SC-08-09', 0.010], ['SC-08-10', 0.008], ['SC-08-11', 0.028], ['SC-08-12', 0.012],
    ['SC-08-13', 0.016], ['SC-08-14', 0.010], ['SC-08-15', 0.008], ['SC-08-16', 0.020],
    ['SC-08-17', 0.010], ['SC-08-18', 0.008], ['SC-08-19', 0.024], ['SC-08-20', 0.0616],
  ];
  const SCEN_RACHAT = new Set(['SC-08-06', 'SC-08-07', 'SC-08-08', 'SC-08-13', 'SC-08-14', 'SC-08-15']);
  const SCEN_TROP_PERCU = new Set(['SC-08-05']);
  const secretaires = m.ref_utilisateur.filter((u) => u.role === 'secretaire');
  const comptables = m.ref_utilisateur.filter((u) => u.role === 'comptable');
  const directeurs = m.ref_utilisateur.filter((u) => u.role === 'directeur');
  m.ops_dossier_remboursement = [];
  // Les credits deja rattaches a un dossier : un credit ne se rembourse qu'une fois.
  const dejaPontes = new Set();
  for (let i = 0; i < VOLUMES.dossiersRemboursement; i++) {
    const scenario = rnd.poids(SCEN_RBC);
    // Coherence interne : un engagement de reprise, une carte grise ou un
    // certificat de situation ne concernent QUE le rachat sec. Le releve de
    // compte ne concerne que le trop-percu.
    const rachat = SCEN_RACHAT.has(scenario) ? true
      : SCEN_TROP_PERCU.has(scenario) ? false : rnd.chance(0.63);
    const contrat = m.ref_contrat_buyback[rnd.entier(0, VOLUMES.buyback - 1)];
    const client = m.ref_client[rnd.entier(0, VOLUMES.clients - 1)];
    const etab = rnd.parmi(m.ref_etablissement.filter((e) => e.actif));
    const dir = directeurs.find((u) => u.etablissements.includes(etab.id)) || rnd.parmi(directeurs);

    // LE PONT VERS L'OUTIL 6, POSE PAR LE MONDE. Un trop-percu, dans la vie,
    // c'est un credit qui dort sur le compte 411 d'un client. On prend donc le
    // montant SUR ce credit quand le client en porte un : le rapprochement
    // outil 6 -> outil 8 devient alors une IDENTITE portee par le monde, et non
    // un rapprochement plausible qu'il faudrait presenter avec des pincettes.
    //
    // `find` ne consomme aucun tirage : la suite du monde ne bouge pas.
    const creditDuClient = rachat ? null : m.ops_ecriture.find((e) => e.sens === 'C'
      && e.client_id === client.id
      && String(e.compte).startsWith('411')
      && !e.lettrage
      && !dejaPontes.has(e.id));
    if (creditDuClient) dejaPontes.add(creditDuClient.id);

    let demande = rachat ? contrat.er_ttc
      : (creditDuClient ? creditDuClient.montant : rnd.montant(45, 3800));
    if (scenario === 'SC-08-06') demande = Math.round((contrat.er_ttc + 2) * 100) / 100;
    if (scenario === 'SC-08-07') demande = Math.round((contrat.er_ttc + 600) * 100) / 100;

    let ibanSaisi = trouverIban(client.iban_id);
    // Meme compte bancaire sous un autre code client : l'empreinte doit le voir.
    if (scenario === 'SC-08-03') ibanSaisi = trouverIban(m.ref_client[rnd.entier(0, VOLUMES.clients - 1)].iban_id) || ibanSaisi;
    const ibanExtrait = scenario === 'SC-08-12' ? ajouterIban(null) : ibanSaisi;
    const montantExtrait = scenario === 'SC-08-11'
      ? Math.round((demande + rnd.montant(35, 480)) * 100) / 100 : demande;

    // Ce que les PIECES du dossier montreront. Ce sont des faits du MONDE -- ce
    // que porte le papier --, pas des attentes de mesure : la verite, elle, dira
    // ce que le dossier doit devenir. Tout se derive du scenario, sans consommer
    // un seul tirage : la suite du monde ne bouge pas.
    //
    // SC-08-16 se partage en deux moities, parce que les deux situations n'ont
    // rien a voir et que le module les traite differemment : une PANNE du
    // fournisseur de lecture, et un document ILLISIBLE. La premiere est une
    // indisponibilite technique, la seconde un fait documentaire.
    const seizeParite = scenario === 'SC-08-16' ? i % 2 === 0 : false;

    m.ops_dossier_remboursement.push({
      id: id(rachat ? 'RBC-2026' : 'TPC-2026', i + 1, 6),
      // Le document illisible se joue sur un RACHAT : sa facture porte le
      // champ de lisibilite que le gabarit du module attend. Un trop-percu
      // prend la panne, que le fournisseur signale quel que soit le gabarit.
      panne_extraction: scenario === 'SC-08-16' && (seizeParite || !rachat),
      piece_illisible: scenario === 'SC-08-16' && !seizeParite && rachat,
      mention_manuscrite: scenario === 'SC-08-13',
      vehicule_gage: scenario === 'SC-08-14',
      estimation_retouchee: scenario === 'SC-08-15',
      motif: rachat ? 'rachat_sec' : 'trop_percu',
      client_id: client.id, etablissement_id: etab.id, societe_id: etab.societe_id,
      vehicule_id: rachat ? contrat.vehicule_id : null,
      immatriculation: rachat ? contrat.immatriculation : null,
      contrat_buyback_id: rachat && scenario !== 'SC-08-08' ? contrat.id : null,
      code_icar: client.code_balance,
      // L'ecriture de l'outil 6 dont ce trop-percu est le remboursement, quand
      // le monde en a trouve une. C'est une IDENTITE, pas une association.
      ecriture_creditrice_id: creditDuClient ? creditDuClient.id : null,
      montant_demande: demande, montant_extrait: montantExtrait, montant_valide: null,
      iban_saisi_id: ibanSaisi.id, iban_extrait_id: ibanExtrait.id,
      deposant_id: rnd.parmi(secretaires).id,
      comptable_id: rnd.parmi(comptables).id,
      directeur_id: scenario === 'SC-08-09' ? (rnd.parmi(directeurs.filter((u) => !u.etablissements.includes(etab.id))) || dir).id : dir.id,
      date_depot: jourAleatoire(rnd), statut: 'depose',
    });
    scenarios.set(m.ops_dossier_remboursement.at(-1).id, scenario);
  }

  // ------------------------------------------------------------ comites
  m.ops_decision_comite = Array.from({ length: VOLUMES.decisionsComite }, (_, i) => {
    const [libelle, famille] = MOTIFS_COMITE[rnd.poids(MOTIFS_COMITE.map((mo, k) => [k,
      mo[1] === 'facturation' ? 3.2 : mo[1] === 'paiement' ? 2.6 : mo[1] === 'divers' ? 1.4 : 1]))];
    const f = m.ops_facture[rnd.entier(0, VOLUMES.factures - 1)];
    // Huit etablissements concentrent l'essentiel : concentration voulue.
    // Concentration voulue, en deux paliers : huit etablissements exposes
    // absorbent une bonne moitie des dossiers, avec une decroissance douce
    // entre eux, et les quarante-six autres se partagent le reste. Une loi de
    // puissance unique donnerait un premier etablissement invraisemblable.
    const rang = rnd.chance(0.55)
      ? Math.floor(Math.pow(rnd(), 1.4) * 8)
      : 8 + Math.floor(rnd() * (VOLUMES.etablissements - 8));
    const etab = m.ref_etablissement[Math.min(rang, VOLUMES.etablissements - 1)];
    return {
      id: id('CMT-2026', i + 1, 6), date: jourAleatoire(rnd, Date.UTC(2025, 11, 1), FIN),
      etablissement_id: etab.id, client_id: f.client_id, facture_id: f.id,
      motif: libelle, famille, montant: f.montant,
      responsable: rnd.poids([['concession', 0.52], ['comptabilite', 0.31], ['client', 0.1], ['financeur', 0.07]]),
      decision: rnd.poids([['a_suivre', 0.58], ['regularise', 0.24], ['perte', 0.09], ['profit', 0.05], ['juridique', 0.04]]),
      echeance: null, statut: rnd.parmi(['ouvert', 'clos']),
    };
  });

  // ------------------------------------------------------------ relance
  const scenariosRelance = new Map();
  const SCEN_REL = [['SC-10-01', 0.307], ['SC-10-02', 0.226], ['SC-10-03', 0.137],
  ['SC-10-04', 0.097], ['SC-10-05', 0.065], ['SC-10-06', 0.032], ['SC-10-07', 0.058], ['SC-10-08', 0.078]];
  const facturesSoldees = m.ops_facture.filter((f) => f.statut === 'soldee');
  m.ops_ligne_relance = Array.from({ length: VOLUMES.lignesRelance }, (_, i) => {
    const scenario = rnd.poids(SCEN_REL);
    // SC-10-02 : reglee en verite, encore ouverte a la balance faute d'affectation.
    const source = scenario === 'SC-10-02' ? facturesSoldees : facturesOuvertes;
    const f = source[rnd.entier(0, source.length - 1)];
    scenariosRelance.set(id('REL', i + 1, 6), scenario);
    return {
      id: id('REL', i + 1, 6), facture_id: f.id, client_id: f.client_id,
      etablissement_id: f.etablissement_id, montant: f.montant, echeance: f.echeance,
      anciennete: rnd.poids([[15, 0.18], [45, 0.27], [75, 0.24], [120, 0.19], [220, 0.12]]),
      relances_deja_envoyees: rnd.poids([[0, 0.52], [1, 0.24], [2, 0.15], [3, 0.09]]),
    };
  });
  for (const [k, v] of scenariosRelance) scenarios.set(k, v);

  // ---------------------------------------------------- profil de payeur
  // Derive de l'historique : ce qu'un systeme comptable sait deja d'un payeur
  // et que personne n'exploite. C'est la matiere de l'enrichissement.
  {
    const parClient = new Map();
    for (const f of m.ops_facture) {
      if (f.statut !== 'soldee') continue;
      let p = parClient.get(f.client_id);
      if (!p) { p = { n: 0, total: 0, min: Infinity, max: 0, societes: new Set(), dernier: null }; parClient.set(f.client_id, p); }
      p.n += 1; p.total += f.montant;
      p.min = Math.min(p.min, f.montant); p.max = Math.max(p.max, f.montant);
      p.societes.add(f.societe_id);
      if (!p.dernier || f.date > p.dernier) p.dernier = f.date;
    }
    m.ref_payeur_profil = [];
    for (const c of m.ref_client) {
      const p = parClient.get(c.id);
      if (!p || p.n < 2) continue;
      m.ref_payeur_profil.push({
        client_id: c.id,
        nb_reglements: p.n,
        montant_moyen: Math.round((p.total / p.n) * 100) / 100,
        montant_min: Math.round(p.min * 100) / 100,
        montant_max: Math.round(p.max * 100) / 100,
        societes_reglees: [...p.societes].sort().join('|'),
        nb_societes: p.societes.size,
        rythme: c.rythme,
        dernier_reglement: p.dernier,
        // Motif de reference observe dans les libelles de ce payeur.
        // Le motif propre a ce payeur, celui qu'il place lui-meme dans ses
        // references de bout en bout. C'est le signal que le contrat de
        // donnees enrichi apporte, et il est distinctif par construction.
        motif_reference: `RF${c.code_balance}`,
      });
    }
  }

  // ---- OUTIL 5 : le lettrage, plante EN DERNIER et sur sa propre graine.
  //
  // L'ordre n'est pas negociable. L'outil 4 est fige et son test final est
  // publie : si cette fabrique tirait un seul nombre dans le flux de `rnd`
  // avant la fin de la generation des virements, tout le monde de l'outil 4
  // se decalerait et sa mesure ne vaudrait plus rien.
  const lettrage = planterLettrage(m);
  m._verites_lettrage = lettrage.verites;
  m._verites_lettrage_historique = lettrage.veritesHisto;

  // ---- OUTIL 6 : le cockpit de pilotage. Additif lui aussi, et tire en
  // dernier : il ne fait qu'apporter le chiffre d'affaires et la cause
  // d'ouverture de chaque creance, sans toucher a une seule ligne existante.
  planterPilotage(m);

  // ---- OUTIL 7 : les dossiers grands comptes. Additif et tire en DERNIER, sur
  // sa propre graine. Il LIT les 3 400 dossiers de livraison deja plantes et
  // leur ajoute la grille de chaque loueur, les valeurs de chaque piece et la
  // verite des anomalies. Aucune ligne existante n'est touchee : l'outil 6
  // continue de pointer vers exactement les memes dossiers.
  const grandsComptes = planterGrandsComptes(m);
  m._verites_grands_comptes = grandsComptes.verites;
  m._verites_anomalies_gc = grandsComptes.veritesAnomalies;

  // Le drapeau de prise est interne a la fabrique : il ne sort pas.
  for (const f of m.ops_facture) delete f._prise;

  // Occurrences d'IBAN et premiere apparition, utiles au moteur d'identification.
  for (const ib of m.ref_iban) if (ib.occurrences === 0) ib.occurrences = 1;

  // Les compositions ne sont PAS une table du monde : elles voyagent a part,
  // hors des donnees publiees, pour alimenter la seule fabrique de verite.
  m._compositions = compositions;
  m._scenarios = scenarios;
  m._payeurs = payeurs;
  m._ambigus = ambigus;

  return m;
}
