// Contrats de scenario : le monde doit MATERIALISER ce que la verite annonce.
//
// Regle posee par l'auteur le 07/09/2026, apres deux incidents de meme nature.
// A chaque fois, la verite declarait une difficulte -- deux candidats a
// egalite, un montant inexplicable -- que le monde ne portait pas. Le moteur
// repondait correctement, la mesure le comptait faux, et le diagnostic partait
// dans une direction imaginaire.
//
// Ce module verifie, virement par virement, que la difficulte annoncee EXISTE.
// Si un scenario ment, la generation echoue. Aucune mesure ne peut plus etre
// publiee sur un monde incoherent.
//
// Il ne lit pas les decisions du moteur : il ne connait que le monde et la
// verite. Ce n'est pas une mesure de performance, c'est un controle d'assiette.

/** Sous-ensembles d'un lot de factures dont la somme fait exactement la cible. */
export function compositions(montants, cibleC, maxFactures = 9, maxSolutions = 4) {
  const items = montants
    .map((m) => Math.round(m * 100))
    .filter((c) => c > 0 && c <= cibleC)
    .sort((a, b) => b - a);
  const n = items.length;
  const suffixe = new Array(n + 1).fill(0);
  for (let i = n - 1; i >= 0; i--) suffixe[i] = suffixe[i + 1] + items[i];

  const solutions = [];
  const courant = [];
  const explorer = (depart, reste) => {
    if (reste === 0) { solutions.push([...courant]); return; }
    if (depart >= n || courant.length >= maxFactures || suffixe[depart] < reste) return;
    if (solutions.length >= maxSolutions) return;
    for (let i = depart; i < n; i++) {
      if (items[i] > reste) continue;
      if (i > depart && items[i] === items[i - 1]) continue;
      courant.push(items[i]);
      explorer(i + 1, reste - items[i]);
      courant.pop();
      if (solutions.length >= maxSolutions) return;
    }
  };
  if (n > 0 && cibleC > 0) explorer(0, cibleC);

  // Deux solutions de meme composition en centimes sont la meme reponse.
  const distinctes = new Set(solutions.map((s) => [...s].sort((a, b) => a - b).join('|')));
  return distinctes.size;
}

/**
 * Verifie les contrats. Rend la liste des manquements, vide si tout tient.
 *
 * @param {object} m monde
 * @param {object} v verite
 */
export function verifierContrats(m, v) {
  const manquements = [];
  const compte = {};
  const noter = (scenario, message, id) => {
    compte[scenario] = compte[scenario] || { verifies: 0, fautes: [] };
    if (message) compte[scenario].fautes.push(`${id} : ${message}`);
  };

  const clientParId = new Map(m.ref_client.map((c) => [c.id, c]));
  const ibanParId = new Map(m.ref_iban.map((i) => [i.id, i]));
  const vehiculeParId = new Map(m.ref_vehicule.map((x) => [x.id, x]));
  const factureParId = new Map(m.ops_facture.map((f) => [f.id, f]));

  // Comptes clients par empreinte de compte bancaire.
  const comptesParEmpreinte = new Map();
  for (const c of m.ref_client) {
    const emp = ibanParId.get(c.iban_id)?.empreinte;
    if (!emp) continue;
    if (!comptesParEmpreinte.has(emp)) comptesParEmpreinte.set(emp, []);
    comptesParEmpreinte.get(emp).push(c);
  }

  // Factures ouvertes par client : c'est le vivier que le moteur examine.
  const ouvertesParClient = new Map();
  for (const f of m.ops_facture) {
    if (f.affectee) continue;
    if (!ouvertesParClient.has(f.client_id)) ouvertesParClient.set(f.client_id, []);
    ouvertesParClient.get(f.client_id).push(f);
  }

  const veriteParId = new Map(v.truth_virement.map((t) => [t.objet_id, t]));

  for (const t of m.ops_virement) {
    const sc = m._scenarios.get(t.id);
    const payeurId = m._payeurs.get(t.id);
    const payeur = clientParId.get(payeurId);
    const verite = veriteParId.get(t.id);
    const cibleC = Math.round(t.montant * 100);
    const texte = `${t.libelle} ${t.reference_bout_en_bout || ''}`.toUpperCase();
    compte[sc] = compte[sc] || { verifies: 0, fautes: [] };
    compte[sc].verifies++;

    // ---- contrat commun a tous : la verite designe le payeur reel
    if (!payeur) { noter(sc, 'aucun payeur enregistre', t.id); continue; }
    if (verite.vrai_client !== payeurId) noter(sc, 'la verite ne designe pas le payeur de la fabrique', t.id);

    // ---- SC-04-10 : deux candidats reellement indiscernables
    if (sc === 'SC-04-10' && verite.decision_attendue === 'exception') {
      const emp = ibanParId.get(t.iban_emetteur_id)?.empreinte;
      const groupe = comptesParEmpreinte.get(emp) || [];
      if (groupe.length < 2) noter(sc, 'un seul compte client sur ce compte bancaire', t.id);

      const memeNom = groupe.every((c) => c.nom === groupe[0].nom);
      if (!memeNom) noter(sc, 'les comptes du groupe ne portent pas la meme raison sociale', t.id);

      // Chacun doit pouvoir expliquer le montant, sans quoi il n'y a pas deux hypotheses.
      const explicatifs = groupe.filter(
        (c) => compositions((ouvertesParClient.get(c.id) || []).map((f) => f.montant), cibleC, 9, 2) >= 1);
      if (explicatifs.length < 2) noter(sc, `${explicatifs.length} compte(s) seulement expliquent le montant`, t.id);

      // Aucun signal independant ne doit departager.
      if (t.reference_bout_en_bout) noter(sc, 'une reference de bout en bout departage', t.id);
      for (const c of groupe) {
        for (const f of ouvertesParClient.get(c.id) || []) {
          if (Math.round(f.montant * 100) !== cibleC) continue;
          if (f.vehicule_id && vehiculeParId.has(f.vehicule_id)) {
            noter(sc, `la facture ${f.id} porte un vehicule qui departage`, t.id);
          }
          if (texte.includes(String(f.numero).toUpperCase())) {
            noter(sc, `le numero de facture ${f.numero} est cite au libelle`, t.id);
          }
        }
      }
    }

    // ---- SC-04-11 : aucun sous-ensemble ne compose le montant
    if (sc === 'SC-04-11') {
      const n = compositions((ouvertesParClient.get(payeurId) || []).map((f) => f.montant), cibleC);
      if (n > 0) noter(sc, `${n} composition(s) expliquent un montant annonce inexplicable`, t.id);
      if (verite.decision_attendue !== 'exception') noter(sc, 'la verite n\'exige pas de main humaine', t.id);
    }

    // ---- SC-04-13 : plusieurs compositions exactes, toutes plausibles
    if (sc === 'SC-04-13' && verite.decision_attendue === 'exception') {
      const n = compositions((ouvertesParClient.get(payeurId) || []).map((f) => f.montant), cibleC);
      if (n < 2) noter(sc, `${n} composition(s) seulement : le piege n'existe pas`, t.id);
    }

    // ---- SC-04-14 : un numero de serie du libelle appartient a un autre compte
    if (sc === 'SC-04-14' && verite.decision_attendue === 'exception') {
      const series = (texte.match(/\b[A-Z0-9]{8}\b/g) || []);
      const etrangere = series.some((s8) => m.ref_vehicule.some(
        (veh) => veh.vin.slice(-8) === s8 && veh.client_id && veh.client_id !== payeurId));
      if (!etrangere) noter(sc, 'aucun numero de serie etranger dans le libelle', t.id);
    }

    // ---- SC-04-04 et SC-04-05 : le donneur d'ordre n'est pas le facture
    if (sc === 'SC-04-04' || sc === 'SC-04-05') {
      if (String(t.nom_donneur_ordre).toUpperCase() === String(payeur.nom).toUpperCase()) {
        noter(sc, 'le nom du donneur d\'ordre est celui du compte facture', t.id);
      }
      if (ibanParId.get(t.iban_emetteur_id)?.titulaire !== payeurId
          && (comptesParEmpreinte.get(ibanParId.get(t.iban_emetteur_id)?.empreinte) || [])
            .every((c) => c.id !== payeurId)) {
        noter(sc, 'aucun signal d\'identite ne rattache le virement au payeur', t.id);
      }
    }

    // ---- SC-04-07 et SC-04-08 : une combinaison, et une seule
    if (sc === 'SC-04-07' || sc === 'SC-04-08') {
      const attendues = m._compositions.get(t.id) || [];
      if (attendues.length < 2) noter(sc, 'la composition attendue ne comporte pas plusieurs factures', t.id);
      const n = compositions((ouvertesParClient.get(payeurId) || []).map((f) => f.montant), cibleC);
      if (n === 0) noter(sc, 'aucune combinaison n\'explique le montant', t.id);
    }

    // ---- SC-04-02, 03, 09, 14 : la reference annoncee est bien dans le libelle
    if (sc === 'SC-04-03' || sc === 'SC-04-09') {
      const premiere = factureParId.get((m._compositions.get(t.id) || [])[0]);
      if (premiere && !texte.includes(String(premiere.numero).toUpperCase())) {
        noter(sc, 'le numero de facture annonce n\'est pas dans le libelle', t.id);
      }
    }
    if (sc === 'SC-04-02' && !/BORD\d{6}/.test(texte)) {
      noter(sc, 'le bordereau annonce n\'est pas dans le libelle', t.id);
    }

    // ---- contrat de sens : seules les classes adverses armees exigent un humain
    const adverse = ['SC-04-10', 'SC-04-11', 'SC-04-13', 'SC-04-14'].includes(sc);
    if (!adverse && verite.decision_attendue !== 'auto') {
      noter(sc, 'un scenario ordinaire est declare non automatisable', t.id);
    }
  }

  for (const [scenario, bilan] of Object.entries(compte).sort()) {
    if (bilan.fautes.length > 0) {
      manquements.push({ scenario, verifies: bilan.verifies, fautes: bilan.fautes });
    }
  }

  return { manquements, bilan: compte };
}
