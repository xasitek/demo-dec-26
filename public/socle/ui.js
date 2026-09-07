// Composants et mise en forme. Une seule famille pour les dix outils.

import { calculer, indicateur } from './kpi.js';

// ---------------------------------------------------------------- format
const NB = new Intl.NumberFormat('fr-FR');
const NB1 = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const EUR = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
const EUR2 = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const nb = (v) => NB.format(Math.round(v));
export const euro = (v) => EUR.format(v);
export const euro2 = (v) => EUR2.format(v);
export const pourcent = (v) => `${NB1.format(v * 100)} %`;
export const jour = (v) => `${NB1.format(v)} j`;
export const date = (v) => (v ? v.split('-').reverse().join('/') : '—');

export function formater(valeur, unite) {
  if (unite === 'euro') return euro(valeur);
  if (unite === 'pourcent') return pourcent(valeur);
  if (unite === 'jour') return jour(valeur);
  return nb(valeur);
}

/** Abrege un grand montant sans perdre l'ordre de grandeur. */
export function euroCourt(v) {
  const a = Math.abs(v);
  if (a >= 1e9) return `${NB1.format(v / 1e9)} Md€`;
  if (a >= 1e6) return `${NB1.format(v / 1e6)} M€`;
  if (a >= 1e3) return `${nb(v / 1e3)} k€`;
  return euro(v);
}

export const echapper = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// ---------------------------------------------------------------- dom
export function el(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
}

// ------------------------------------------------------- carte indicateur

/**
 * Carte d'indicateur. Le chiffre est CALCULE, jamais ecrit.
 * Un clic ouvre la formule, la duree reellement mesuree et les lignes sources.
 */
export function carteKpi(id, options = {}) {
  const d = indicateur(id);
  const noeud = el(`
    <button type="button" class="kpi ${options.ton ? `kpi--${options.ton}` : ''}" data-kpi="${id}">
      <span class="kpi__lib">${echapper(options.libelle || d.libelle)}</span>
      <span class="kpi__val" data-role="val">·</span>
      <span class="kpi__pied"><span data-role="pied">calcul…</span><span>pourquoi ?</span></span>
    </button>`);

  calculer(id, options.ctx).then((r) => {
    const val = noeud.querySelector('[data-role="val"]');
    animerVers(val, r.valeur, d.unite);
    noeud.querySelector('[data-role="pied"]').textContent =
      `${nb(r.lignes ? r.lignes.length : 0)} lignes · ${r.ms.toFixed(0)} ms`;
  }).catch((e) => {
    noeud.querySelector('[data-role="val"]').textContent = '—';
    noeud.querySelector('[data-role="pied"]').textContent = e.message;
  });

  noeud.addEventListener('click', () => ouvrirPourquoiKpi(id, options.ctx));
  return noeud;
}

/** Compteur anime. La valeur d'arrivee vient du calcul, jamais d'une constante. */
function animerVers(noeud, cible, unite) {
  const duree = 620;
  const t0 = performance.now();
  const pas = (t) => {
    const p = Math.min(1, (t - t0) / duree);
    const e = 1 - Math.pow(1 - p, 3);
    noeud.textContent = formater(cible * e, unite);
    if (p < 1) requestAnimationFrame(pas);
    else noeud.textContent = formater(cible, unite);
  };
  requestAnimationFrame(pas);
}

// ------------------------------------------------------------- modale

let modale = null;
export function ouvrirModale(titre, contenu) {
  if (!modale) {
    modale = el(`<dialog><div class="modale__t"><h2 data-role="t"></h2><button class="btn btn--fant" data-role="x">Fermer</button></div><div class="modale__c" data-role="c"></div></dialog>`);
    document.body.append(modale);
    modale.querySelector('[data-role="x"]').addEventListener('click', () => modale.close());
  }
  modale.querySelector('[data-role="t"]').textContent = titre;
  const c = modale.querySelector('[data-role="c"]');
  c.replaceChildren(typeof contenu === 'string' ? el(`<div>${contenu}</div>`) : contenu);
  modale.showModal();
  return modale;
}

async function ouvrirPourquoiKpi(id, ctx) {
  const r = await calculer(id, ctx);
  const apercu = (r.lignes || []).slice(0, 40);
  const colonnes = apercu.length ? Object.keys(apercu[0]).slice(0, 7) : [];
  const corps = el(`<div>
    <p>${echapper(r.libelle)}</p>
    <div class="formule">${echapper(r.formule)}</div>
    <div class="paires" style="margin:.9rem 0">
      <div><div class="paire__c">Valeur calculée</div><div class="paire__v">${formater(r.valeur, r.unite)}</div></div>
      <div><div class="paire__c">Lignes retenues</div><div class="paire__v">${nb((r.lignes || []).length)}</div></div>
      <div><div class="paire__c">Tables lues</div><div class="paire__v">${r.sources.join(', ')}</div></div>
      <div><div class="paire__c">Durée mesurée sur cet appareil</div><div class="paire__v">${r.ms.toFixed(1)} ms</div></div>
    </div>
    <h3 style="margin:.4rem 0 .5rem">Les lignes derrière le chiffre <small style="font-weight:400;color:var(--texte-3)">(40 premières)</small></h3>
    <div class="tab__cadre"><table class="tab">
      <thead><tr>${colonnes.map((c) => `<th>${echapper(c)}</th>`).join('')}</tr></thead>
      <tbody>${apercu.map((l) => `<tr>${colonnes.map((c) => `<td class="${typeof l[c] === 'number' ? 'num' : ''}">${echapper(typeof l[c] === 'number' ? nb(l[c]) : l[c])}</td>`).join('')}</tr>`).join('')}</tbody>
    </table></div>
    <p class="mesure" style="margin-top:.7rem">Indicateur ${echapper(r.id)} · recalculé à l'affichage, jamais stocké.</p>
  </div>`);
  ouvrirModale('Pourquoi ce chiffre ?', corps);
}

// ------------------------------------------------------------- barres

export function barres(donnees, options = {}) {
  const max = Math.max(...donnees.map((d) => d.valeur), 1);
  const fmt = options.format || nb;
  return el(`<div class="barres">${donnees.map((d) => `
    <div class="barre">
      <span>${echapper(d.cle ?? '—')}</span>
      <span class="barre__p"><i style="width:${(d.valeur / max) * 100}%; background:${d.ton || 'var(--accent)'}"></i></span>
      <span class="barre__v">${fmt(d.valeur)}</span>
    </div>`).join('')}</div>`);
}

// ------------------------------------------------------------- fiche

/** La fiche unique des dix outils : DONNÉE → CONTRÔLE → DÉCISION → IMPACT. */
export function fichePreuve({ donnee, controle, decision, impact }) {
  const bande = (eti, classe, contenu) =>
    `<div class="fiche__bande fiche__bande--${classe}"><div class="fiche__eti">${eti}</div>${contenu}</div>`;
  return el(`<div class="fiche">
    ${bande('Donnée', 'donnee', donnee)}
    ${bande('Contrôle', 'controle', controle)}
    ${bande('Décision', 'decision', decision)}
    ${bande('Impact', 'impact', impact)}
  </div>`);
}

export function paires(liste) {
  return `<div class="paires">${liste.filter(Boolean).map(([c, v]) =>
    `<div><div class="paire__c">${echapper(c)}</div><div class="paire__v">${echapper(v)}</div></div>`).join('')}</div>`;
}

export function listePreuves(preuves) {
  return `<div class="preuves">${preuves.map((p) => `
    <div class="preuve preuve--${p.poids >= 0 ? 'pos' : 'neg'}">
      <span class="preuve__s">${p.poids >= 0 ? '✓' : '✗'}</span>
      <span>${echapper(p.libelle)}</span>
      <span class="preuve__p">${p.poids >= 0 ? '+' : ''}${p.poids}</span>
    </div>`).join('')}</div>`;
}

export function tableau(lignes, colonnes, options = {}) {
  const n = options.limite || 200;
  return el(`<div class="tab__cadre"><table class="tab">
    <thead><tr>${colonnes.map((c) => `<th>${echapper(c.titre)}</th>`).join('')}</tr></thead>
    <tbody>${lignes.slice(0, n).map((l) => `<tr>${colonnes.map((c) => {
      const v = c.valeur(l);
      return `<td class="${c.classe || ''}">${c.brut ? v : echapper(v)}</td>`;
    }).join('')}</tr>`).join('')}</tbody>
  </table></div>`);
}

export function chrono(label, ms) {
  return `<span class="mesure">${echapper(label)} ${ms.toFixed(0)} ms — mesuré sur cet appareil</span>`;
}
