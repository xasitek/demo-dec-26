// Contrats de scenario du lettrage : le monde doit MATERIALISER ce que la
// verite annonce.
//
// Meme discipline que pour l'outil 4, et pour la meme raison. Un scenario qui
// se declare ambigu sans l'etre fait compter comme faux un rapprochement juste,
// et envoie le diagnostic dans une direction imaginaire. Si un contrat cede, la
// generation echoue et aucune mesure ne peut etre publiee.
//
// Ce module ne connait que le monde et la verite. Il ne lit aucune decision du
// moteur : ce n'est pas une mesure de performance, c'est un controle d'assiette.

import { compositions } from './contrats.js';

/** Les tolerances du moteur, redites ici pour que le controle soit independant. */
const BAREME_1V1 = [
  [60, 5], [90, 10], [180, 25], [365, 50], [730, 100], [null, 500],
];

const REFERENCES_GENERIQUES = ['ASOLDER', 'TRANSFERT', 'LIAISON', 'DIVERS', 'REGUL', 'ACOMPTE', 'COMPTANT'];

const AUJOURD_HUI = Date.parse('2026-09-01T00:00:00Z');

const jours = (isoDate) => Math.max(0, Math.round((AUJOURD_HUI - Date.parse(isoDate + 'T00:00:00Z')) / 86400000));

const tolerance = (dates) => {
  const age = Math.max(...dates.map(jours));
  for (const [borne, euros] of BAREME_1V1) if (borne === null || age < borne) return euros;
  return 500;
};

const cent = (x) => Math.round(x * 100);

/**
 * Verifie les contrats du lettrage.
 *
 * @param {object} m monde
 * @param {object} v verite
 */
export function verifierContratsLettrage(m, v) {
  const manquements = [];
  const bilan = {};
  const noter = (sc, message, id) => {
    bilan[sc] = bilan[sc] || { verifies: 0, fautes: [] };
    bilan[sc].fautes.push(`${id} : ${message}`);
  };

  const parLot = new Map();
  for (const e of m.ops_ecriture) {
    if (!e.lot_demo) continue;
    if (!parLot.has(e.lot_demo)) parLot.set(e.lot_demo, []);
    parLot.get(e.lot_demo).push(e);
  }

  // Le vivier ouvert par compte client : c'est ce que le moteur examinera.
  const ouvertesParClient = new Map();
  for (const e of m.ops_ecriture) {
    if (e.lettrage) continue;
    if (!ouvertesParClient.has(e.client_id)) ouvertesParClient.set(e.client_id, []);
    ouvertesParClient.get(e.client_id).push(e);
  }

  const vehiculeParVin = new Map(m.ref_vehicule.map((x) => [x.vin, x]));

  for (const t of v.truth_lettrage) {
    const sc = t.code_scenario;
    bilan[sc] = bilan[sc] || { verifies: 0, fautes: [] };
    bilan[sc].verifies++;

    const lignes = parLot.get(t.objet_id) || [];
    if (lignes.length === 0) { noter(sc, 'aucune ecriture rattachee au lot', t.objet_id); continue; }

    const attendues = String(t.groupe_attendu || '').split('|').filter(Boolean);
    if (attendues.length !== lignes.length) {
      noter(sc, `la verite annonce ${attendues.length} ecritures, le monde en porte ${lignes.length}`, t.objet_id);
    }

    const debits = lignes.filter((e) => e.sens === 'D');
    const credits = lignes.filter((e) => e.sens === 'C');
    const sommeD = debits.reduce((s, e) => s + cent(e.montant), 0);
    const sommeC = credits.reduce((s, e) => s + cent(e.montant), 0);
    const ecart = Math.abs(sommeD - sommeC) / 100;
    const tol = tolerance(lignes.map((e) => e.date));

    // ---- SC-05-01 : au moins une cle forte partagee, et un montant exact
    if (sc === 'SC-05-01') {
      if (debits.length !== 1 || credits.length !== 1) {
        noter(sc, 'le cas annonce un contre un', t.objet_id);
      } else {
        const partagee = ['vin', 'reference_piece'].some(
          (k) => debits[0][k] && debits[0][k] === credits[0][k]);
        if (!partagee) noter(sc, 'aucune cle forte partagee', t.objet_id);
        if (ecart > 0.001) noter(sc, `montant non identique : ${ecart} EUR d'ecart`, t.objet_id);
      }
    }

    // ---- SC-05-06 : une seule composition, et elle mobilise plusieurs ecritures
    if (sc === 'SC-05-06') {
      if (credits.length !== 1 || debits.length < 3) {
        noter(sc, 'le cas annonce un reglement contre plusieurs ecritures', t.objet_id);
      } else {
        const n = compositions(debits.map((e) => e.montant), cent(credits[0].montant));
        if (n !== 1) noter(sc, `${n} composition(s) au lieu d'une seule`, t.objet_id);
      }
    }

    // ---- SC-05-07 : aucune cle ne suffit seule, et les indices convergent
    if (sc === 'SC-05-07') {
      if (debits.length !== 1 || credits.length !== 1) {
        noter(sc, 'le cas annonce un contre un', t.objet_id);
      } else {
        if (debits[0].reference_piece || credits[0].reference_piece) {
          noter(sc, 'une reference de piece departage : la convergence n\'est plus necessaire', t.objet_id);
        }
        if (ecart < 0.01) noter(sc, 'le montant est identique : le cas ne demontre rien', t.objet_id);
        if (ecart > tol) noter(sc, `ecart de ${ecart} EUR hors tolerance de ${tol} EUR`, t.objet_id);
        const concordants = ['vin', 'immatriculation', 'ordre_reparation'].filter(
          (k) => debits[0][k] && debits[0][k] === credits[0][k]).length;
        if (concordants < 3) noter(sc, `${concordants} cles concordantes seulement, trois attendues`, t.objet_id);
      }
    }

    // ---- SC-05-08 : le numero de serie du reglement appartient a un autre compte
    if (sc === 'SC-05-08') {
      if (debits.length !== 1 || credits.length !== 1) {
        noter(sc, 'le cas annonce un contre un', t.objet_id);
      } else {
        const a = debits[0].vin;
        const b = credits[0].vin;
        if (!a || !b) noter(sc, 'les deux cotes doivent porter un numero de serie', t.objet_id);
        else if (a === b) noter(sc, 'les numeros de serie sont identiques : aucune contradiction', t.objet_id);
        else {
          const veh = vehiculeParVin.get(b);
          if (!veh || !veh.client_id || veh.client_id === debits[0].client_id) {
            noter(sc, 'le numero de serie du reglement n\'appartient pas a un autre compte', t.objet_id);
          }
        }
        if (ecart > 0.001) noter(sc, 'les montants doivent etre identiques : c\'est le piege', t.objet_id);
      }
    }

    // ---- SC-05-09 : le meme ecart, deux anciennetes, deux verdicts
    if (sc === 'SC-05-09') {
      if (Math.abs(ecart - 12) > 0.001) noter(sc, `ecart de ${ecart} EUR, douze attendus`, t.objet_id);
      const age = Math.min(...lignes.map((e) => jours(e.date)));
      if (t.verdict === 'DOIT_RESTER_HUMAIN' && ecart <= tolerance(lignes.map((e) => e.date))) {
        noter(sc, `a ${age} jours la tolerance de ${tol} EUR couvre l'ecart : le cas ne doit pas etre humain`, t.objet_id);
      }
      if (t.verdict === 'TRUE_MATCH' && ecart > tol) {
        noter(sc, `a ${age} jours la tolerance de ${tol} EUR ne couvre pas l'ecart`, t.objet_id);
      }
    }

    // ---- SC-05-10 : toutes les cles concordent, le solde ne tombe pas
    if (sc === 'SC-05-10') {
      if (ecart <= tol) noter(sc, `ecart de ${ecart} EUR couvert par la tolerance de ${tol} EUR`, t.objet_id);
      if (debits.length === 1 && credits.length === 1
          && !(debits[0].vin && debits[0].vin === credits[0].vin)) {
        noter(sc, 'les cles doivent concorder : c\'est ce qui rend le refus demonstratif', t.objet_id);
      }
    }

    // ---- SC-05-11 : deux compositions equilibrent le meme total
    if (sc === 'SC-05-11') {
      if (credits.length !== 1) noter(sc, 'un seul reglement attendu', t.objet_id);
      else {
        const n = compositions(debits.map((e) => e.montant), cent(credits[0].montant));
        if (n < 2) noter(sc, `${n} composition(s) : le piege n'existe pas`, t.objet_id);
      }
    }

    // ---- SC-05-12 : aucun rapprochement possible
    if (sc === 'SC-05-12') {
      if (lignes.length !== 1) noter(sc, 'une seule ecriture attendue', t.objet_id);
      else {
        const vivier = ouvertesParClient.get(lignes[0].client_id) || [];
        const opposees = vivier.filter((e) => e.sens !== lignes[0].sens);
        const n = compositions(opposees.map((e) => e.montant), cent(lignes[0].montant));
        if (n > 0) noter(sc, `${n} composition(s) expliquent un reglement annonce inexplicable`, t.objet_id);
      }
    }

    // ---- SC-05-15 : garantie, le seuil de cinq cents euros decide
    if (sc === 'SC-05-15') {
      if (t.verdict === 'DOIT_RESTER_HUMAIN' && ecart <= 500) {
        noter(sc, `ecart de ${ecart} EUR : en deca du seuil, ce n'est pas une anomalie`, t.objet_id);
      }
      if (t.verdict === 'TRUE_MATCH' && ecart > 500) {
        noter(sc, `ecart de ${ecart} EUR : au-dela du seuil, ce n'est pas un lettrage`, t.objet_id);
      }
      if (!lignes.every((e) => e.compte === '4116000')) {
        noter(sc, 'les ecritures doivent etre portees au compte de garantie', t.objet_id);
      }
    }

    // ---- contrat commun : un lot annonce TRUE_MATCH doit pouvoir se solder
    //
    // Cinq classes en sont exemptees, et chacune a sa propre regle d'equilibre,
    // plus large que le bareme d'anciennete : la garantie constructeur tolere
    // cinq cents euros d'ecart traites en ecriture d'ecart, et les quatre
    // apurements -- compte d'attente, acompte dormant, ecritures anciennes,
    // compte ferme, nom unique -- ont chacun leur bareme.
    const APUREMENTS = ['SC-05-15', 'SC-05-16', 'SC-05-17', 'SC-05-19', 'SC-05-20', 'SC-05-22'];
    if (t.verdict === 'TRUE_MATCH' && ecart > tol && !APUREMENTS.includes(sc)) {
      noter(sc, `annonce lettrable mais ecart de ${ecart} EUR hors tolerance de ${tol} EUR`, t.objet_id);
    }
  }

  // ---------------------------------------- les lettrages deja poses
  const parCode = new Map();
  for (const e of m.ops_ecriture) {
    if (!e.lettrage) continue;
    if (!parCode.has(e.lettrage)) parCode.set(e.lettrage, []);
    parCode.get(e.lettrage).push(e);
  }
  for (const t of v.truth_lettrage_historique) {
    const sc = 'HISTO-' + t.etat_reel;
    bilan[sc] = bilan[sc] || { verifies: 0, fautes: [] };
    bilan[sc].verifies++;

    const lignes = parCode.get(t.objet_id) || [];
    if (lignes.length === 0) { noter(sc, 'aucune ecriture portant ce code', t.objet_id); continue; }

    const sommeD = lignes.filter((e) => e.sens === 'D').reduce((s, e) => s + cent(e.montant), 0);
    const sommeC = lignes.filter((e) => e.sens === 'C').reduce((s, e) => s + cent(e.montant), 0);
    const ecart = Math.abs(sommeD - sommeC) / 100;
    const series = new Set(lignes.map((e) => e.vin).filter(Boolean));

    if (t.etat_reel === 'coherent') {
      if (ecart > 0.001) noter(sc, `annonce coherent mais ${ecart} EUR d'ecart`, t.objet_id);
      if (series.size > 1) noter(sc, 'annonce coherent mais deux numeros de serie', t.objet_id);
    }
    if (t.etat_reel === 'solde_incorrect' && ecart <= tolerance(lignes.map((e) => e.date))) {
      noter(sc, `annonce hors solde mais ${ecart} EUR d'ecart, couvert par la tolerance`, t.objet_id);
    }
    if (t.etat_reel === 'contradictoire' && series.size < 2) {
      noter(sc, 'annonce contradictoire mais un seul numero de serie', t.objet_id);
    }
    if (t.etat_reel === 'mauvaise_cle') {
      const refs = lignes.map((e) => String(e.reference_piece || '').toUpperCase()).filter(Boolean);
      const porteuse = refs.some((r) => !REFERENCES_GENERIQUES.includes(r));
      if (porteuse || series.size > 0) {
        noter(sc, 'annonce sans cle mais le groupe porte une cle exploitable', t.objet_id);
      }
    }
    if (t.etat_reel === 'doublon') {
      const credits = lignes.filter((e) => e.sens === 'C').map((e) => cent(e.montant));
      if (new Set(credits).size === credits.length) {
        noter(sc, 'annonce doublon mais aucun reglement en double', t.objet_id);
      }
    }
  }

  for (const [scenario, b] of Object.entries(bilan).sort()) {
    if (b.fautes.length > 0) manquements.push({ scenario, verifies: b.verifies, fautes: b.fautes });
  }

  return { manquements, bilan };
}
