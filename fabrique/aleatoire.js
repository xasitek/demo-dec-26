// Aleatoire deterministe, lexique invente, formats valides mais non attribues.
// AUCUNE source externe : ce module ne lit ni fichier, ni base, ni reseau.

export const GRAINE = 20260906;

/**
 * Graine de la seconde population aveugle.
 *
 * Elle est distincte de la graine du monde, et elle le reste : une fois les
 * erreurs de la premiere population etudiees, cette premiere population ne
 * demontre plus rien. Seule une population tiree apres coup, sur laquelle rien
 * n'a ete regle, mesure ce que vaut l'outil.
 */
export const GRAINE_BLIND_2 = 20260907;

/**
 * Graine de la population aveugle finale.
 *
 * BLIND_TEST_2 a servi au diagnostic : on y a ouvert un faux positif, et la
 * fabrique a ete corrigee ensuite. Il est donc devenu un jeu de validation.
 * Cette troisieme population est tiree apres le gel du generateur, des
 * contrats, des poids, des seuils et des regles d'arret. Elle est mesuree une
 * fois, et c'est elle qui porte le chiffre publie.
 */
export const GRAINE_BLIND_3 = 20260908;

/**
 * Graine du test final.
 *
 * BLIND_TEST_3 a revele un defaut reel du moteur -- un numero de serie
 * contradictoire que l'extracteur de references ne voyait pas parce qu'il ne
 * portait aucun chiffre. Le defaut corrige, BLIND_TEST_3 est devenu a son tour
 * un jeu de validation. Cette quatrieme population est tiree apres la
 * correction, et c'est elle qui porte le chiffre publie.
 */
export const GRAINE_BLIND_4 = 20260909;

/**
 * Graines de l'outil 5 -- le lettrage.
 *
 * Elles sont DISTINCTES de celles de l'outil 4, et c'est ce qui garantit que
 * la fabrique du lettrage ne decale pas d'un tirage le monde de l'affectation.
 * L'outil 4 est fige, son test final est publie : son monde ne doit pas bouger.
 */
export const GRAINE_O5 = 20260910;
export const GRAINE_O5_BLIND = 20260911;

/**
 * Graine du test final du lettrage.
 *
 * BLIND_O5 a revele deux defauts reels du moteur -- un reclassement sans cle,
 * et un controle d'unicite de composition qui ne valait que pour une methode.
 * Les defauts corriges, BLIND_O5 devient un jeu de validation, et c'est cette
 * population-ci qui porte le chiffre publie. Mesuree une seule fois.
 */
export const GRAINE_O5_BLIND_2 = 20260912;

/**
 * Graine du test final du lettrage, troisieme et derniere.
 *
 * BLIND_O5_2 a servi au diagnostic : ses trois faux positifs ont ete ouverts,
 * et deux causes generalisables ont ete corrigees -- une recherche
 * combinatoire qui s'autorisait une tolerance, et un apurement global qui
 * melangeait deux dossiers distincts. Elle devient donc un jeu de validation.
 *
 * Cette population est tiree APRES le gel complet : moteur, regles, baremes,
 * cascade, generateur, contrats, scenarios, verite. Elle est mesuree une seule
 * fois, et c'est elle qui porte le chiffre publie.
 */
export const GRAINE_O5_BLIND_3 = 20260913;

/**
 * Graine du cockpit de pilotage.
 *
 * L'outil 6 ne fait aucune prediction : il consolide et retraite ce que les
 * outils 4 et 5 ont decide. Il n'a donc pas de population aveugle -- un test
 * en aveugle sur un total qui doit tomber juste n'aurait aucun sens. Ce qui
 * remplace le test aveugle, ce sont des controles de reconciliation : la somme
 * des etablissements doit faire le groupe, les tranches d'anciennete doivent
 * faire le total, et l'encours brut moins les retraitements doit faire
 * l'encours retraite. Ils font echouer la construction en cas d'ecart.
 */
export const GRAINE_O6 = 20260914;

/**
 * Outil 7 — les dossiers grands comptes.
 *
 * La fabrique de l'outil 7 est ADDITIVE : elle lit les 3 400 dossiers de
 * livraison deja plantes et leur ajoute la grille documentaire de chaque
 * loueur, les valeurs de chaque piece, et la verite des anomalies. Elle tire
 * donc sur sa propre graine, en dernier, pour que rien du monde des outils 4,
 * 5 et 6 ne se decale d'une ligne.
 */
export const GRAINE_O7 = 20260915;

/**
 * La population aveugle de l'outil 7.
 *
 * Un quart des dossiers tire ses anomalies sur CETTE graine, jamais sur celle
 * de la calibration. C'est ce qui garantit qu'un reglage du moteur sur les
 * cas connus ne peut pas atteindre les cas de la mesure finale.
 */
export const GRAINE_O7_BLIND = 20260916;

/** Generateur deterministe (mulberry32). Meme graine = meme univers. */
export function generateur(graine = GRAINE) {
  let a = graine >>> 0;
  const f = () => {
    a |= 0; a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
  f.entier = (min, max) => min + Math.floor(f() * (max - min + 1));
  f.parmi = (liste) => liste[Math.floor(f() * liste.length)];
  f.poids = (paires) => { // [[valeur, poids], ...]
    const total = paires.reduce((s, p) => s + p[1], 0);
    let x = f() * total;
    for (const [v, p] of paires) { x -= p; if (x <= 0) return v; }
    return paires[paires.length - 1][0];
  };
  f.chance = (p) => f() < p;
  f.montant = (min, max, pas = 0.01) =>
    Math.round((min + f() * (max - min)) / pas) * pas;
  return f;
}

// ---------------------------------------------------------------- lexique

const SYL = ['vor', 'tal', 'mes', 'kur', 'dan', 'pel', 'rin', 'sol', 'bry', 'nau',
  'fex', 'gil', 'mur', 'teg', 'val', 'zor', 'lim', 'sab', 'ryn', 'dol',
  'cav', 'nem', 'tri', 'osk', 'plu', 'ferd', 'juv', 'kal', 'wen', 'brix'];

const SUFFIXES_SOC = ['Automobiles', 'Distribution', 'Motors', 'Auto Services',
  'Mobilite', 'Concessions', 'Auto Groupe'];

const PRENOMS_SYL = ['Ame', 'Loi', 'Ner', 'Sab', 'Tio', 'Vel', 'Kir', 'Mae', 'Ruo', 'Dhen'];
const NOMS_SYL = ['Barn', 'Volt', 'Kress', 'Mielt', 'Sarno', 'Dulce', 'Farge', 'Quint', 'Rovel', 'Thane'];

const ACTIVITES = ['Industrie', 'Logistique', 'Batiment', 'Sante', 'Conseil', 'Energie',
  'Transport', 'Agroalimentaire', 'Numerique', 'Assurance', 'Distribution', 'Formation'];

const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

export function motInvente(rnd, syllabes = 2) {
  let m = '';
  for (let i = 0; i < syllabes; i++) m += rnd.parmi(SYL);
  return cap(m);
}

export function nomSociete(rnd) {
  return `${motInvente(rnd, rnd.entier(2, 3)).toUpperCase()} ${rnd.parmi(SUFFIXES_SOC)}`;
}

export function nomPersonne(rnd) {
  return `${rnd.parmi(PRENOMS_SYL)}${rnd.parmi(SYL)} ${rnd.parmi(NOMS_SYL)}${rnd.parmi(SYL)}`.replace(/(\w)(\w*)/g, (_, a, b) => a.toUpperCase() + b);
}

export function nomEntreprise(rnd) {
  return `${motInvente(rnd, 2).toUpperCase()} ${rnd.parmi(ACTIVITES).toUpperCase()}`;
}

/** Le meme nom, ecrit comme une banque le tronque : majuscules, 35 caracteres. */
export function tronqueBanque(nom) {
  return nom.toUpperCase().replace(/[^A-Z0-9 ]/g, '').slice(0, 35).trim();
}

// ---------------------------------------------------------------- formats

const VIN_CHARS = 'ABCDEFGHJKLMNPRSTUVWXYZ0123456789'; // sans I, O, Q
const VIN_VALEUR = { A: 1, B: 2, C: 3, D: 4, E: 5, F: 6, G: 7, H: 8, J: 1, K: 2, L: 3, M: 4, N: 5, P: 7, R: 9, S: 2, T: 3, U: 4, V: 5, W: 6, X: 7, Y: 8, Z: 9 };
const VIN_POIDS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

/**
 * Numero de serie au format ISO 3779, cle de controle juste.
 * Identifiant constructeur « SYN », compose pour n'appartenir a aucun constructeur.
 */
export function numeroSerie(rnd) {
  let v = 'SYN';
  for (let i = 3; i < 17; i++) v += rnd.parmi(VIN_CHARS.split(''));
  const car = v.split('');
  let somme = 0;
  for (let i = 0; i < 17; i++) {
    const c = car[i];
    const val = /\d/.test(c) ? Number(c) : VIN_VALEUR[c];
    somme += val * VIN_POIDS[i];
  }
  const reste = somme % 11;
  car[8] = reste === 10 ? 'X' : String(reste);
  return car.join('');
}

const LETTRES = 'ABCDEFGHJKLMNPQRSTVWXYZ'.split('');

/** Immatriculation au format en vigueur, sur une serie en Z non emise. */
export function immatriculation(rnd) {
  return `Z${rnd.parmi(LETTRES)}-${String(rnd.entier(1, 999)).padStart(3, '0')}-${rnd.parmi(LETTRES)}${rnd.parmi(LETTRES)}`;
}

const BANQUE_NON_ATTRIBUEE = '99999';

/** IBAN francais a cle modulo 97 juste, sur un code banque non attribue. */
export function iban(rnd) {
  const guichet = String(rnd.entier(10000, 99999));
  const compte = Array.from({ length: 11 }, () => rnd.parmi('0123456789ABCDEFGHJKLMNPRSTUVWXYZ'.split(''))).join('');
  const bban = BANQUE_NON_ATTRIBUEE + guichet + compte;
  const cleRib = String(97 - (mod97(convertirLettres(bban) + '00') % 97)).padStart(2, '0');
  const corps = BANQUE_NON_ATTRIBUEE + guichet + compte + cleRib;
  const cle = String(98 - mod97(convertirLettres(corps + 'FR00'))).padStart(2, '0');
  return `FR${cle}${corps}`;
}

/**
 * Un IBAN francais valide, DETERMINISTE, sans toucher au generateur aleatoire.
 *
 * Meme doctrine que `iban()` : banque 99999, qui n'est attribuee a aucun
 * etablissement bancaire francais, vraie cle RIB et vraies cles MOD 97. La
 * difference est qu'il ne consomme aucun tirage : il se derive d'un entier.
 * C'est ce qui permet de le poser sur une piece deja fabriquee sans decaler
 * d'un cran la suite des tirages du monde.
 */
export function ibanDeterministe(graine) {
  const n = Math.abs(Math.trunc(graine));
  const guichet = String(10000 + (n * 7919) % 89999);
  const compte = String((n * 104729 + 1) % 100000000000).padStart(11, '0');
  const bban = BANQUE_NON_ATTRIBUEE + guichet + compte;
  const cleRib = String(97 - (mod97(convertirLettres(bban) + '00') % 97)).padStart(2, '0');
  const corps = BANQUE_NON_ATTRIBUEE + guichet + compte + cleRib;
  const cle = String(98 - mod97(convertirLettres(corps + 'FR00'))).padStart(2, '0');
  return `FR${cle}${corps}`;
}

function convertirLettres(s) {
  return s.replace(/[A-Z]/g, (c) => String(c.charCodeAt(0) - 55));
}

function mod97(numerique) {
  let reste = 0;
  for (const c of numerique) reste = (reste * 10 + Number(c)) % 97;
  return reste;
}

export function ibanValide(v) {
  const s = v.replace(/\s/g, '').toUpperCase();
  if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(s)) return false;
  return mod97(convertirLettres(s.slice(4) + s.slice(0, 4))) === 1;
}

/** Empreinte aveugle : compare deux IBAN sans exposer leur valeur. */
export function empreinte(valeur) {
  let h1 = 0x811c9dc5, h2 = 0x01000193;
  for (let i = 0; i < valeur.length; i++) {
    h1 = Math.imul(h1 ^ valeur.charCodeAt(i), 16777619) >>> 0;
    h2 = Math.imul(h2 + valeur.charCodeAt(i) * (i + 7), 2246822519) >>> 0;
  }
  return (h1.toString(16).padStart(8, '0') + h2.toString(16).padStart(8, '0')).toUpperCase();
}

// ---------------------------------------------------------------- dates

export const DEBUT = Date.UTC(2025, 2, 1);   // 01/03/2025
export const FIN = Date.UTC(2026, 8, 1);     // 01/09/2026

export function jourAleatoire(rnd, debut = DEBUT, fin = FIN) {
  const t = debut + Math.floor(rnd() * (fin - debut));
  const d = new Date(t);
  const jour = d.getUTCDay();
  if (jour === 0) d.setUTCDate(d.getUTCDate() + 1);
  if (jour === 6) d.setUTCDate(d.getUTCDate() + 2);
  return iso(d);
}

export const iso = (d) => d.toISOString().slice(0, 10);
export const ajouterJours = (isoDate, n) => {
  const d = new Date(isoDate + 'T00:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return iso(d);
};
export const ecartJours = (a, b) =>
  Math.round((Date.parse(b + 'T00:00:00Z') - Date.parse(a + 'T00:00:00Z')) / 86400000);
