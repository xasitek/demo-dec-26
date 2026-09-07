// Contrats de scenario de l'outil 7 : le monde doit MATERIALISER ce que la
// verite annonce.
//
// Meme discipline que pour les outils 4 et 5, et pour la meme raison, payee
// cher a l'epoque : un scenario qui se declare ambigu sans l'etre fait compter
// comme fausse une decision juste, et envoie le diagnostic dans une direction
// imaginaire. Si un contrat cede, la generation echoue et aucune mesure ne peut
// etre publiee.
//
// Ce module ne connait que le monde et la verite. Il ne lit aucune decision du
// moteur : ce n'est pas une mesure de performance, c'est un controle d'assiette.

/** Regroupe une liste par une cle. */
const par = (liste, cle) => {
  const m = new Map();
  for (const x of liste) {
    const k = x[cle];
    if (!m.has(k)) m.set(k, []);
    m.get(k).push(x);
  }
  return m;
};

const cent = (x) => Math.round(Number(x) * 100);

/**
 * Verifie les contrats des dossiers grands comptes.
 *
 * @param {object} m monde
 * @param {object} v verite
 */
export function verifierContratsGrandsComptes(m, v) {
  const manquements = [];
  const ajouter = (id, quoi) => manquements.push(`${id} : ${quoi}`);

  const dossiers = m.ops_dossier_gc || [];
  const pieces = par(m.ops_piece_gc || [], 'dossier_id');
  const veriteParId = new Map((v.truth_dossier_gc || []).map((x) => [x.objet_id, x]));
  const anomaliesParId = par(v.truth_anomalie_gc || [], 'objet_id');
  const grilleParLoueur = new Map();
  for (const e of m.ref_exigence_piece || []) {
    if (!grilleParLoueur.has(e.loueur_id)) grilleParLoueur.set(e.loueur_id, new Map());
    grilleParLoueur.get(e.loueur_id).set(e.type_piece, e.exigence);
  }
  const loueurParId = new Map((m.ref_loueur_grille || []).map((l) => [l.loueur_id, l]));
  const electrifie = new Set(['electrique', 'hybride']);

  // -------------------------------------------------------- contrat d'assiette
  //
  // Les dossiers de l'outil 7 doivent etre EXACTEMENT ceux du monde. C'est la
  // condition posee au lancement du lot : l'outil 6 continue de pointer vers
  // les memes dossiers, et l'outil 7 n'en cree aucun.
  const idsMonde = new Set((m.ops_dossier_livraison || []).map((d) => d.id));
  const idsGc = new Set(dossiers.map((d) => d.id));
  if (idsMonde.size !== idsGc.size) {
    ajouter('ASSIETTE', `${idsGc.size} dossiers dans l'outil 7 contre ${idsMonde.size} dans le monde`);
  }
  for (const id of idsMonde) {
    if (!idsGc.has(id)) { ajouter('ASSIETTE', `le dossier ${id} du monde n'a pas d'enrichissement`); break; }
  }

  for (const d of dossiers) {
    const p = pieces.get(d.id) || [];
    const parType = new Map(p.map((x) => [x.type_piece, x]));
    const ver = veriteParId.get(d.id);
    const anos = new Set((anomaliesParId.get(d.id) || []).map((a) => a.code_anomalie));
    const grille = grilleParLoueur.get(d.loueur_id) || new Map();
    const loueur = loueurParId.get(d.loueur_id);
    const bat = electrifie.has(d.energie);

    if (!ver) { ajouter(d.id, 'aucune verite de dossier'); continue; }

    // ---- invariants de grille
    for (const [type, exigence] of grille) {
      if ('non_demandee' === exigence && parType.has(type)) {
        ajouter(d.id, `piece ${type} creee alors que la grille de ${d.loueur_id} ne la demande pas`);
      }
      if ('obligatoire' === exigence && !parType.has(type)) {
        ajouter(d.id, `piece ${type} obligatoire chez ${d.loueur_id} et absente de la liste attendue`);
      }
    }
    if (!parType.has('PVL')) ajouter(d.id, 'aucun PV de livraison attendu');
    if (!parType.has('F1')) ajouter(d.id, 'aucune facture attendue');

    // ---- invariants de verite
    if (anos.has('PVL_NON_TAMPONNE') && !loueur?.exige_tampon) {
      ajouter(d.id, "le tampon est reproche a un loueur qui ne l'exige pas");
    }
    for (const code of anos) {
      if (code.startsWith('BAT_') && !bat) {
        ajouter(d.id, `${code} plante sur un vehicule ni electrique ni hybride`);
      }
    }
    if (ver.nb_anomalies !== anos.size) {
      ajouter(d.id, `la verite annonce ${ver.nb_anomalies} anomalies et en porte ${anos.size}`);
    }

    // ---- le verdict attendu doit decouler des faits
    const manquePiece = [...grille].some(([type, ex]) =>
      'obligatoire' === ex && parType.has(type) && !parType.get(type).presente);
    const illisible = p.some((x) => !x.lisible);
    const verdictAttendu = (manquePiece || illisible) ? 'incomplet'
      : (anos.size > 0 ? 'non_conforme' : 'conforme');
    if (ver.verdict_attendu !== verdictAttendu) {
      ajouter(d.id, `verdict ${ver.verdict_attendu} alors que les faits donnent ${verdictAttendu}`);
    }

    // ---- les scenarios, un par un
    const pvl = parType.get('PVL');
    const bdc = parType.get('BDC');
    const f1 = parType.get('F1');
    const cpi = parType.get('CPI');

    switch (d.code_scenario) {
      case 'SC-07-11': // conforme
        if (anos.size > 0) ajouter(d.id, `declare conforme et porte ${anos.size} anomalie(s)`);
        if (p.some((x) => !x.presente || !x.lisible)) {
          ajouter(d.id, 'declare conforme et porte une piece absente ou illisible');
        }
        break;

      case 'SC-07-12': // PV ni date ni signe
        if (pvl && (pvl.date_lue !== null || pvl.signe !== false)) {
          ajouter(d.id, 'le PV est declare ni date ni signe mais porte une date ou une signature');
        }
        break;

      case 'SC-07-13': // PV non tamponne
        if (loueur?.exige_tampon && pvl && pvl.tampon !== false) {
          ajouter(d.id, 'le PV est declare non tamponne mais porte un tampon');
        }
        break;

      case 'SC-07-14': // numero de commande absent de la facture
        if (grille.get('BDC') !== 'non_demandee' && f1 && f1.numero_commande_lu !== null) {
          ajouter(d.id, 'le numero de commande est declare absent mais figure sur la facture');
        }
        break;

      case 'SC-07-15': // numero divergent
        if (grille.get('BDC') !== 'non_demandee' && bdc && f1
            && bdc.numero_lu === f1.numero_commande_lu) {
          ajouter(d.id, 'les numeros sont declares divergents et sont identiques');
        }
        break;

      case 'SC-07-16': // montant divergent, au-dela de la tolerance
        if (grille.get('BDC') !== 'non_demandee' && bdc) {
          const ecart = Math.abs(cent(bdc.montant_lu) - cent(d.montant_facture));
          if (ecart <= (loueur?.tolerance_centimes ?? 0)) {
            ajouter(d.id, `ecart de montant declare et pourtant dans la tolerance (${ecart} centimes)`);
          }
        }
        break;

      case 'SC-07-17': // prix de batterie absent
        if (bat && f1 && f1.prix_batterie !== null) {
          ajouter(d.id, 'le prix de la batterie est declare absent et il est present');
        }
        break;

      case 'SC-07-18': // mention HT ou TTC manquante
        if (bat && f1 && f1.mention_devise !== false) {
          ajouter(d.id, 'la mention de devise est declaree manquante et elle est presente');
        }
        break;

      case 'SC-07-19': // CPI absent
        if (grille.get('CPI') === 'obligatoire' && cpi && cpi.presente !== false) {
          ajouter(d.id, 'le CPI est declare absent et il est present');
        }
        break;

      case 'SC-07-20': // immatriculation divergente
        if (pvl && pvl.immatriculation_lue === d.immatriculation) {
          ajouter(d.id, "l'immatriculation du PV est declaree divergente et elle correspond");
        }
        break;

      case 'SC-07-21': // facture au conducteur
        if (f1 && f1.adresse_facturation !== 'client') {
          ajouter(d.id, 'la facture est declaree adressee au conducteur et elle porte le loueur');
        }
        break;

      case 'SC-07-22': // quatre anomalies cumulees
        if (anos.size < 2) {
          ajouter(d.id, `cumul declare et seulement ${anos.size} anomalie(s) retenue(s)`);
        }
        break;

      case 'SC-07-23': // vehicule non declare livre
        if (pvl && pvl.presente !== false) {
          ajouter(d.id, 'le vehicule est declare non livre et le PV est present');
        }
        break;

      case 'SC-07-24': // numero illisible : le doute ne se tranche pas
        if (grille.get('BDC') !== 'non_demandee') {
          if (bdc && (bdc.lisible !== false || bdc.numero_lu !== null)) {
            ajouter(d.id, 'le numero est declare illisible et il se lit');
          }
          if (ver.verdict_attendu !== 'incomplet') {
            ajouter(d.id, `numero illisible et verdict ${ver.verdict_attendu} au lieu de incomplet`);
          }
        }
        break;

      case 'SC-07-25': // piece absente que la grille ne demande pas
        if (anos.size > 0) {
          ajouter(d.id, 'une piece optionnelle absente ne doit produire aucune anomalie');
        }
        break;

      case 'SC-07-26': // ecart dans la tolerance
        if (anos.size > 0) {
          ajouter(d.id, 'un ecart dans la tolerance du loueur ne doit produire aucune anomalie');
        }
        // L'ecart doit etre pose sur le bon de commande, non nul, et dans la
        // tolerance : les trois conditions, sinon le scenario ne demontre rien.
        if (!bdc) {
          ajouter(d.id, 'ecart tolere annonce sans bon de commande pour le porter');
        } else {
          const ecart = Math.abs(cent(bdc.montant_lu) - cent(d.montant_facture));
          const tol = loueur?.tolerance_centimes ?? 0;
          if (ecart === 0) ajouter(d.id, 'ecart tolere annonce et montant identique : rien a tolerer');
          if (ecart > tol) ajouter(d.id, `ecart de ${ecart} centimes au-dela de la tolerance de ${tol}`);
        }
        break;

      case 'SC-07-27': // HT ou TTC manquant sur la batterie
        if (bat && f1 && f1.prix_batterie_ht !== null && f1.prix_batterie_ttc !== null) {
          ajouter(d.id, 'les montants HT et TTC sont declares manquants et ils sont presents');
        }
        break;

      default:
        break;
    }
  }

  // --------------------------------------------- les grilles sont differentes
  //
  // Huit grilles identiques ne demontreraient rien : l'outil existe parce que
  // le meme vehicule livre a deux loueurs ne demande pas le meme dossier.
  const signatures = new Set();
  for (const [loueurId, g] of grilleParLoueur) {
    signatures.add([...g].sort().map(([t, e]) => `${t}=${e}`).join(','));
  }
  if (signatures.size < 6) {
    manquements.push(`GRILLES : seulement ${signatures.size} grilles distinctes sur 8 loueurs`);
  }

  // ------------------------------------- chaque code du referentiel se mesure
  const plantees = new Set((v.truth_anomalie_gc || []).map((a) => a.code_anomalie));
  for (const a of m.ref_anomalie || []) {
    if (!plantees.has(a.code)) {
      manquements.push(`REFERENTIEL : le code ${a.code} n'a aucune population, il ne se mesure pas`);
    }
  }

  return { manquements: manquements.slice(0, 40), total: manquements.length };
}
