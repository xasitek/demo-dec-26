// Acces au MONDE synthetique. Ce module ne connait pas la verite de reference :
// elle vit dans un fichier separe, charge par socle/verite.js et par lui seul.
//
// Principe : on charge des LIGNES. Aucun indicateur n'est stocke, tout est
// recalcule a l'affichage. Voir socle/kpi.js.

// Resolu par rapport a CE module : le site fonctionne aussi bien a la racine
// (serveur local) que sous un prefixe (GitHub Pages sert /demo-dec-26/).
const BASE = new URL('../donnees/monde', import.meta.url).href;

const cache = new Map();
const index = new Map();
let manifeste = null;

/** Decode une table colonnaire en liste d'objets. */
function decoder(brut) {
  const noms = Object.keys(brut.cols);
  const lignes = new Array(brut.n);
  const acces = noms.map((nom) => {
    const c = brut.cols[nom];
    return c.d ? (k) => c.d[c.i[k]] : (k) => c.v[k];
  });
  for (let k = 0; k < brut.n; k++) {
    const o = {};
    for (let j = 0; j < noms.length; j++) o[noms[j]] = acces[j](k);
    lignes[k] = o;
  }
  return lignes;
}

export async function manifest() {
  if (!manifeste) manifeste = await (await fetch(`${BASE}/index.json`)).json();
  return manifeste;
}

/** Charge une table du monde. Le resultat est mis en cache pour la session. */
export async function table(nom) {
  if (cache.has(nom)) return cache.get(nom);
  const reponse = await fetch(`${BASE}/${nom}.json`);
  if (!reponse.ok) throw new Error(`Table introuvable : ${nom}`);
  const lignes = decoder(await reponse.json());
  cache.set(nom, lignes);
  return lignes;
}

/** Charge plusieurs tables en parallele. */
export async function tables(...noms) {
  const chargees = await Promise.all(noms.map(table));
  return Object.fromEntries(noms.map((n, i) => [n, chargees[i]]));
}

/** Index par identifiant, construit une seule fois par table. */
export async function parId(nom, cle = 'id') {
  const clef = `${nom}:${cle}`;
  if (index.has(clef)) return index.get(clef);
  const m = new Map();
  for (const l of await table(nom)) m.set(l[cle], l);
  index.set(clef, m);
  return m;
}

/** Groupement par valeur de colonne, construit une seule fois. */
export async function groupePar(nom, cle) {
  const clef = `g:${nom}:${cle}`;
  if (index.has(clef)) return index.get(clef);
  const m = new Map();
  for (const l of await table(nom)) {
    const v = l[cle];
    let liste = m.get(v);
    if (!liste) { liste = []; m.set(v, liste); }
    liste.push(l);
  }
  index.set(clef, m);
  return m;
}

export function tablesChargees() {
  return [...cache.keys()];
}

export function lignesEnMemoire() {
  let n = 0;
  for (const v of cache.values()) n += v.length;
  return n;
}
