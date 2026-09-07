// Constantes metier partagees entre la fabrique et les moteurs.
// Les origines sont NEUTRALISEES : voir catalogue/regles.js pour les references SRC-*.
// La correspondance avec les developpements reels vit HORS de ce projet.

/** Controle anti-surpaiement sur l'engagement de reprise. Origine SRC-O8-C07. */
export const ControleBuyBack = {
  /** Tolerance en euros au-dela de l'engagement de reprise. */
  TOLERANCE: 3.0,
  /** Ne bloque QUE le surpaiement. Payer moins ne bloque pas. */
  evaluer(montantDemande, erTtc) {
    if (erTtc == null) return { applicable: false };
    const ecart = Math.round((montantDemande - erTtc) * 100) / 100;
    return { applicable: true, erTtc, ecart, surpaiement: ecart > ControleBuyBack.TOLERANCE };
  },
};

/** Bornes dures du moteur de lettrage. Origine SRC-O5-G01 a G05. */
export const GardeFous = {
  /** Prescription de l'action du professionnel contre le consommateur. */
  PRESCRIPTION_JOURS: 730,
  ECART_GARANTIE_MAX: 500,
};

/** Tolerances graduees par anciennete. Origine SRC-O5-M08. */
export const TOLERANCES_AGE = [
  { jusqu_a: 30, euros: 5 },
  { jusqu_a: 90, euros: 25 },
  { jusqu_a: 180, euros: 60 },
  { jusqu_a: 365, euros: 120 },
  { jusqu_a: 99999, euros: 200 },
];

export function tolerancePour(ageJours) {
  return (TOLERANCES_AGE.find((t) => ageJours <= t.jusqu_a) || TOLERANCES_AGE.at(-1)).euros;
}

/** Seuils de routage du moteur d'identification. Origine SRC-O4-S01. */
export const SEUILS_IDENTIFICATION = {
  automatique: 95,
  validation: 70,
  rejet: 40,
  ecartMinimalEntreCandidats: 3,
};
