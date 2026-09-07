// Catalogue de la suite : les dix outils, la chaine, et le catalogue de regles.
//
// FILIATION NEUTRALISEE. Les origines sont designees par des references
// SRC-* et par des libelles fonctionnels. Aucun nom de depot, aucun compte,
// aucun chemin de fichier de production ne figure ici ni ailleurs dans le
// build publie. La table de correspondance vit HORS de ce projet.

export const CHAINE = [
  {
    titre: 'Diagnostiquer',
    outils: [
      { n: 1, cle: '1-cadrage', nom: 'Cadrer' },
      { n: 2, cle: '2-cartographie', nom: 'Cartographier' },
      { n: 3, cle: '3-maturite', nom: 'Mesurer' },
    ],
  },
  {
    titre: 'Fiabiliser',
    outils: [
      { n: 4, cle: '4-affectation', nom: 'Identifier les règlements' },
      { n: 5, cle: '5-lettrage', nom: 'Lettrer les écritures' },
    ],
  },
  {
    titre: 'Piloter et sécuriser',
    outils: [
      { n: 6, cle: '6-pilotage', nom: 'Piloter le BFR et le DSO' },
      { n: 7, cle: '7-grands-comptes', nom: 'Sécuriser les grands comptes' },
      { n: 8, cle: '8-remboursements', nom: 'Contrôler les remboursements' },
    ],
  },
  {
    titre: 'Agir',
    outils: [
      { n: 9, cle: '9-comites', nom: 'Décider en comité' },
      { n: 10, cle: '10-relance', nom: 'Relancer utilement' },
    ],
  },
];

export const OUTILS = {
  '1-cadrage': {
    n: 1, nom: 'Cadrer la mission',
    probleme: "Une mission d'optimisation du cycle créances se vend et se cadre à l'aveugle, sans que personne ne sache ce qu'elle couvre ni ce qu'elle coûte.",
    role: "Il produit le périmètre, les livrables, la charge et les honoraires à partir des seuls volumes du client, et il énonce les clauses que la réception du fichier des écritures impose.",
    origine: 'Modèle de cadrage rédigé pour la mission',
    kpis: ['SOC-CLIENTS', 'SOC-ECRITURES', 'SOC-FACTURES'],
    cas: [], statut: 'a-construire',
  },
  '2-cartographie': {
    n: 2, nom: 'Cartographier le processus',
    probleme: "Personne ne sait où, dans la chaîne de la commande à l'encaissement, la responsabilité change de mains et une créance devient irrécouvrable.",
    role: "Il pose la cartographie de référence du processus, chiffre chacune de ses ruptures de responsabilité sur les données, et renvoie vers l'outil qui la traite.",
    origine: 'Cartographie de référence produite pour la mission',
    kpis: ['SOC-FACTURES', 'SOC-ENCOURS', 'SOC-SANS-REFERENCE'],
    cas: [], statut: 'a-construire',
  },
  '3-maturite': {
    n: 3, nom: 'Mesurer la maturité',
    probleme: "Le degré de maîtrise du cycle créances se juge à l'impression, jamais à la mesure, donc aucun progrès ne se démontre.",
    role: "Il note sept axes sur des données, non sur des déclarations, et se remesure après déploiement pour établir ce que les outils ont réellement changé.",
    origine: 'Grille de diagnostic à sept axes produite pour la mission',
    kpis: ['SOC-NON-LETTRE', 'SOC-CODES-DIVERGENTS', 'SOC-SANS-REFERENCE'],
    cas: [], statut: 'a-construire',
  },
  '4-affectation': {
    n: 4, nom: 'Identifier les règlements',
    probleme: "Un virement arrive avec un libellé tronqué par la banque et sans référence exploitable : personne ne sait à quel client ni à quelles factures l'imputer.",
    role: "Il croise les signaux disponibles pour proposer un payeur et une composition de factures, avec un score et ses preuves, et il refuse quand il n'est pas sûr.",
    origine: "Moteur opérationnel d'identification des règlements développé sur la mission",
    kpis: ['SOC-VIREMENTS', 'SOC-SANS-REFERENCE', 'SOC-VIREMENTS-MONTANT'],
    cas: [
      { titre: 'Virement multi-sociétés de 126 843 €', sous: 'Six factures, trois sociétés, un seul virement' },
      { titre: 'Payeur différent du facturé', sous: 'Le nom du donneur d\'ordre ne correspond à personne' },
      { titre: 'Deux candidats à trois points d\'écart', sous: 'L\'outil refuse de trancher seul' },
      { titre: 'Cas volontairement insoluble', sous: 'Aucun sous-ensemble de factures n\'explique le montant' },
    ],
    statut: 'a-construire',
  },
  '5-lettrage': {
    n: 5, nom: 'Lettrer les écritures',
    probleme: "Un lettrage généraliste échoue sur un compte client automobile : les montants ne se répondent pas et les clés fortes manquent une fois sur deux.",
    role: "Il applique une cascade de méthodes, de la plus sûre à la plus permissive, sur des clés sectorielles, et une ligne déjà consommée n'est jamais reprise.",
    origine: 'Moteur opérationnel de lettrage développé sur la mission',
    kpis: ['SOC-ECRITURES', 'SOC-NON-LETTRE', 'SOC-MONTANT-NON-LETTRE'],
    cas: [
      { titre: 'Cinq indices convergents, aucune clé unique', sous: 'Le rapprochement se fait quand même' },
      { titre: 'Numéro de série contradictoire', sous: 'Deux montants identiques, et le lettrage est refusé' },
      { titre: 'Même écart, deux anciennetés', sous: 'Accepté à quatorze mois, refusé à vingt jours' },
      { titre: 'Créance prescrite', sous: 'Une règle de droit encodée dans le moteur' },
    ],
    statut: 'a-construire',
  },
  '6-pilotage': {
    n: 6, nom: 'Piloter le BFR et le DSO',
    probleme: "L'encours client se lit de deux façons qui ne donnent pas le même chiffre, et aucune ne descend jusqu'à l'écriture qui l'explique.",
    role: "Il consolide l'encours, le délai de recouvrement et le besoin en fonds de roulement, affiche les deux lectures côte à côte, et descend du groupe jusqu'à l'écriture.",
    origine: 'Tableau de bord opérationnel de pilotage financier développé sur la mission',
    kpis: ['SOC-ENCOURS', 'SOC-MONTANT-NON-LETTRE', 'SOC-FACTURES'],
    cas: [
      { titre: 'Les deux lectures de l\'encours', sous: 'Net contre soldes débiteurs, et leur écart' },
      { titre: 'Du groupe à l\'écriture en six clics', sous: 'Descente complète sur un montant' },
    ],
    statut: 'a-construire',
  },
  '7-grands-comptes': {
    n: 7, nom: 'Sécuriser les grands comptes',
    probleme: "Chaque loueur exige un dossier de pièces différent, et une facture reste impayée pour une seule pièce absente que personne n'a identifiée.",
    role: "Il reconstruit le dossier de chaque payeur depuis la balance, applique la grille de pièces propre à ce payeur, et chiffre le montant que chaque pièce absente bloque.",
    origine: 'Chaîne documentaire opérationnelle des livraisons grands comptes',
    kpis: ['SOC-DOSSIERS-LIVRAISON', 'SOC-ENCOURS'],
    cas: [
      { titre: 'Attestation de batterie absente', sous: 'Paiement bloqué, montant affiché' },
      { titre: 'Le même dossier chez un autre loueur', sous: 'Cette pièce n\'y est pas exigée' },
    ],
    statut: 'a-construire',
  },
  '8-remboursements': {
    n: 8, nom: 'Contrôler les remboursements',
    probleme: "Un remboursement client est un décaissement, et rien ne garantit qu'il n'est pas un doublon, un surpaiement, ou un virement vers le mauvais compte.",
    role: "Il fait passer chaque demande par une machine à états et par une série de contrôles exécutés au passage du dossier, et refuse de produire un fichier de paiement sans la séquence de signatures attendue.",
    origine: 'Module intégré de contrôle des remboursements clients',
    kpis: ['SOC-REMBOURSEMENTS', 'SOC-BUYBACK'],
    cas: [
      { titre: 'Surpaiement d\'engagement de reprise bloqué', sous: '600 € au-dessus du contrat, tolérance de 3 €' },
      { titre: 'Doublon détecté dès le dépôt', sous: 'Refusé par la base, pas par un balayage horaire' },
      { titre: 'Même compte bancaire, deux codes clients', sous: 'L\'empreinte d\'IBAN le voit' },
      { titre: 'Panne de lecture des pièces', sous: 'Ne devient jamais un refus' },
    ],
    statut: 'a-construire',
  },
  '9-comites': {
    n: 9, nom: 'Décider en comité',
    probleme: "Un comité de créances produit des décisions que personne n'agrège : la même cause revient chaque mois sans jamais être traitée à la racine.",
    role: "Il structure chaque décision en donnée, sur une nomenclature de quarante-deux motifs, et retourne aux équipes ce que leurs propres séances disent.",
    origine: 'Plateforme opérationnelle de pilotage des comités de créances',
    kpis: ['SOC-COMITES', 'SOC-COMITES-SOLVABILITE'],
    cas: [
      { titre: 'Ce que disent 9 800 comptes rendus', sous: 'Une fois agrégés par famille de motif' },
      { titre: 'La solvabilité est une cause marginale', sous: 'Deux motifs sur quarante-deux' },
    ],
    statut: 'a-construire',
  },
  '10-relance': {
    n: 10, nom: 'Relancer utilement',
    probleme: "On relance sur l'ancienneté, donc on relance des factures déjà payées, bloquées pour une pièce, ou dues par un financeur. La relation client paie l'erreur.",
    role: "Il qualifie chaque ligne du portefeuille avec ce que les autres outils ont établi, et ne conserve que les créances réellement relançables, par niveau.",
    origine: 'Module opérationnel de recouvrement et de relance multi-niveaux',
    kpis: ['SOC-RELANCE-PORTEFEUILLE', 'SOC-ENCOURS'],
    cas: [
      { titre: 'Pourquoi 690 factures sur 1 000 ne doivent pas être relancées', sous: 'Le tri, motif par motif' },
      { titre: 'Trois relances sans réponse', sous: 'Niveau supérieur, pas une quatrième relance identique' },
    ],
    statut: 'a-construire',
  },
};

export const cleParNumero = (n) => Object.keys(OUTILS).find((k) => OUTILS[k].n === n);

// --------------------------------------------------------------- regles
// Catalogue declaratif. Chaque regle porte une reference NEUTRE.

export const REGLES = [
  { ref: 'SRC-O4-S01', outil: 4, nom: 'Seuils de routage', enonce: "Au-dessus de 95 points l'affectation est automatique ; entre 70 et 95 elle est proposée à validation ; en dessous de 40 elle est écartée.", origine: "Moteur opérationnel d'identification des règlements" },
  { ref: 'SRC-O4-S02', outil: 4, nom: 'Écart minimal entre candidats', enonce: "Si les deux meilleurs candidats se tiennent à moins de trois points, l'outil ne tranche pas et remet la décision au professionnel.", origine: "Moteur opérationnel d'identification des règlements" },
  { ref: 'SRC-O5-M01', outil: 5, nom: 'Ordre de la cascade', enonce: "Les méthodes s'exécutent de la plus sûre à la plus permissive, et une ligne déjà consommée n'est jamais reprise par une méthode plus faible.", origine: 'Moteur opérationnel de lettrage' },
  { ref: 'SRC-O5-M03', outil: 5, nom: 'Clé sur le numéro de série', enonce: "Le rapprochement par numéro de série ne s'applique qu'à des lignes portant le même véhicule ; un numéro contradictoire interdit le lettrage.", origine: 'Moteur opérationnel de lettrage' },
  { ref: 'SRC-O5-M08', outil: 5, nom: 'Tolérance graduée par ancienneté', enonce: "L'écart admis croît avec l'ancienneté de la créance : 5 € à trente jours, 25 € à quatre-vingt-dix, 200 € au-delà d'un an.", origine: 'Moteur opérationnel de lettrage' },
  { ref: 'SRC-O5-G01', outil: 5, nom: 'Borne de prescription', enonce: "Aucune créance de plus de sept cent trente jours n'est lettrée automatiquement : c'est le délai de prescription de l'action du professionnel contre le consommateur.", origine: 'Moteur opérationnel de lettrage' },
  { ref: 'SRC-O5-G05', outil: 5, nom: 'Solde nul obligatoire', enonce: "Un lettrage dont la somme des débits moins celle des crédits n'est pas nulle est refusé avant tout dépôt.", origine: 'Moteur opérationnel de lettrage' },
  { ref: 'SRC-O7-D01', outil: 7, nom: 'Grille de pièces par payeur', enonce: "Les pièces exigées ne sont pas une liste figée : elles dépendent du payeur, et pour un véhicule électrique certains payeurs exigent une attestation de capacité de batterie.", origine: 'Chaîne documentaire des livraisons grands comptes' },
  { ref: 'SRC-O7-D02', outil: 7, nom: 'Dossier reconstruit depuis la balance', enonce: "Le dossier n'est pas entretenu à la main : il est reconstruit depuis la balance âgée, donc une facture réglée en sort par construction.", origine: 'Chaîne documentaire des livraisons grands comptes' },
  { ref: 'SRC-O8-C07', outil: 8, nom: 'Anti-surpaiement sur engagement de reprise', enonce: "Le montant demandé est comparé à l'engagement de reprise contractuel. Seul le surpaiement bloque, au-delà de trois euros de tolérance. Payer moins ne bloque pas.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O8-C09', outil: 8, nom: 'Unicité des dossiers actifs', enonce: "Un seul dossier actif par immatriculation, ou par code client selon le motif. La contrainte est portée par la base, donc le doublon est refusé à l'instant du dépôt.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O8-C10', outil: 8, nom: 'Empreinte aveugle d\'IBAN', enonce: "Deux dossiers de codes clients différents portant le même compte bancaire sont rapprochés par une empreinte, sans que le compte soit exposé.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O8-C23', outil: 8, nom: 'Écart entre les trois valeurs', enonce: "Toute modification de l'IBAN ou du montant entre la saisie, la lecture de la pièce et la validation est signalée avant paiement.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O8-C26', outil: 8, nom: 'Validation limitée au périmètre', enonce: "Un directeur ne peut valider que les dossiers de son propre établissement.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O8-C28', outil: 8, nom: 'Garde de paiement', enonce: "Aucun fichier de paiement n'est produit si le journal ne porte pas, dans l'ordre et signées par les bons rôles, le dépôt, la validation par le directeur du périmètre avec un acteur différent du déposant, puis la confirmation du comptable.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O8-C34', outil: 8, nom: 'Anti-rejeu', enonce: "L'identifiant du message de paiement est conservé une seule fois : un rejeu réutilise le fichier existant et n'émet jamais un second virement.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O8-A01', outil: 8, nom: 'La lecture automatique ne refuse jamais', enonce: "L'analyse des pièces produit un avis indicatif et surchargeable. Une panne technique n'est jamais un refus : le dossier part vers le professionnel.", origine: 'Module intégré de contrôle des remboursements' },
  { ref: 'SRC-O9-N01', outil: 9, nom: 'Nomenclature des motifs', enonce: "Quarante-deux motifs de non-encaissement, en six familles. Deux seulement mettent en cause la solvabilité du client.", origine: 'Plateforme de pilotage des comités de créances' },
  { ref: 'SRC-O10-R01', outil: 10, nom: 'Niveaux de relance', enonce: "Une stratégie porte des niveaux ordonnés, chacun avec son délai, son type d'action et sa condition de déclenchement. Trois relances sans réponse font passer au niveau supérieur, jamais à une quatrième relance identique.", origine: 'Module de recouvrement et de relance multi-niveaux' },
  { ref: 'SRC-O10-R02', outil: 10, nom: 'Qualification avant relance', enonce: "Une ligne n'est relançable que si aucun autre outil n'a établi qu'elle est déjà payée, bloquée pour pièce, due par un financeur, en litige, ou sous promesse.", origine: 'Module de recouvrement et de relance multi-niveaux' },
];

/** Le noyau transposable, et ce que le secteur ajoute. */
export const TRANSPOSITION = {
  generique: ['montant', 'tiers', 'facture', 'règlement', 'date', 'établissement',
    'société', 'compte', 'sens', 'lettrage', 'échéance', 'journal'],
  sectoriel: ['numéro de série', 'immatriculation', 'ordre de réparation', 'financeur',
    'loueur', 'engagement de reprise', 'garantie constructeur', 'prime'],
};
