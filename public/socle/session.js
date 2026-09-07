// Couche de session.
//
// Les donnees livrees sont IMMUABLES : rien ne les ecrit, jamais. Toute action
// du visiteur (valider, refuser, deposer une piece, changer un seuil) ecrit ici,
// par-dessus. Les ecrans lisent le monde PLUS la session.
//
// Consequence : deux visiteurs ne se voient pas, fermer l'onglet efface tout,
// et il est impossible d'abimer la demonstration pour quelqu'un d'autre.

const CLE = 'suite-creances:session';

let etat = charger();
const abonnes = new Set();

function charger() {
  try {
    const brut = sessionStorage.getItem(CLE);
    return brut ? JSON.parse(brut) : vide();
  } catch { return vide(); }
}

function vide() {
  return { actions: [], surcharges: {}, parametres: {}, ouvertLe: new Date().toISOString() };
}

function sauver() {
  try { sessionStorage.setItem(CLE, JSON.stringify(etat)); } catch { /* mode prive : la session vit en memoire */ }
  for (const f of abonnes) f(etat);
}

/** Surcharge d'un objet : { [objet_id]: { champ: valeur } }. */
export function surcharge(objetId) {
  return etat.surcharges[objetId] || null;
}

/** Applique la session par-dessus une ligne du monde immuable. */
export function avecSession(ligne) {
  const s = ligne && etat.surcharges[ligne.id];
  return s ? { ...ligne, ...s, _modifie: true } : ligne;
}

export function appliquer(objetId, champs, libelle) {
  etat.surcharges[objetId] = { ...(etat.surcharges[objetId] || {}), ...champs };
  etat.actions.push({ objet_id: objetId, libelle, horodatage: new Date().toISOString() });
  sauver();
}

export function parametre(cle, valeur) {
  if (valeur === undefined) return etat.parametres[cle];
  etat.parametres[cle] = valeur;
  sauver();
  return valeur;
}

export const actions = () => etat.actions;
export const nbActions = () => etat.actions.length;

/** Remet la demonstration dans son etat initial. Aucun rechargement. */
export function reinitialiser() {
  etat = vide();
  sauver();
}

export function surChangement(f) { abonnes.add(f); return () => abonnes.delete(f); }
