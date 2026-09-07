// Page RESULTATS.
//
// C'est le SEUL ecran de la plateforme qui ouvre la verite de reference, et il
// ne l'ouvre qu'APRES traitement, pour mesurer. Aucun moteur n'importe ce
// fichier : la separation est verifiee par garde/controles.js.

import { table, manifest } from '../socle/donnees.js';
import { verite, veriteOuverte } from '../socle/verite.js';
import { carteKpi, el, echapper, nb, euro, euroCourt, barres, pourcent, paires } from '../socle/ui.js';
import { repartir, somme } from '../socle/kpi.js';
import { FAMILLES_LIB } from './libelles.js';

export async function pageResultats() {
  const info = await manifest();

  const racine = el(`<div class="vue--large">
    <div class="fil"><a href="/">Accueil</a> › Résultats</div>
    <h1>Ce que produit la suite sur l'environnement synthétique</h1>
    <p style="color:var(--texte-2);max-width:72ch">Tous les chiffres de cette page sont recalculés
      à l'affichage, à partir des lignes. Cliquez n'importe lequel : vous verrez sa formule, sa
      durée d'exécution mesurée et les lignes qui le composent.</p>

    <div class="carte" style="margin-top:1.2rem">
      <div class="carte__titre"><h2>Le périmètre traité</h2><small>ce que les outils ont à traiter</small></div>
      <div class="grille g4" data-role="volumes"></div>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>La difficulté, mesurée</h2>
        <small>ce qui rend le rapprochement impossible à la main</small></div>
      <div class="grille g3" data-role="difficulte"></div>
      <p class="note note--attention" style="margin-top:.9rem">Ces obstacles sont <b>fabriqués
        volontairement</b> et paramétrés. Sans eux, les outils n'auraient rien à résoudre, et la
        démonstration ne prouverait rien.</p>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>Les comités, une fois agrégés</h2>
        <small>ce que disent ${nb(info.tables.ops_decision_comite)} décisions</small></div>
      <div class="grille g2">
        <div><h3 style="margin-bottom:.6rem;font-size:.85rem;color:var(--texte-2)">Montants par famille de motif</h3>
          <div data-role="familles"></div></div>
        <div><h3 style="margin-bottom:.6rem;font-size:.85rem;color:var(--texte-2)">Concentration par établissement</h3>
          <div data-role="concentration"></div></div>
      </div>
      <div style="margin-top:1rem" class="grille g3" data-role="comite-kpi"></div>
    </div>

    <div class="carte">
      <div class="carte__titre"><h2>La vérité de référence</h2>
        <small>ouverte seulement ici, et seulement pour mesurer</small></div>
      <p>Le jeu synthétique connaît la bonne réponse pour chaque objet, écrite au moment de sa
        fabrication. Les moteurs ne la voient jamais : ils traitent le monde sans elle. Elle
        n'est ouverte qu'ici, <b>après traitement</b>, pour calculer précision, rappel et
        montants correctement traités.</p>
      <div data-role="verite"></div>
    </div>
  </div>`);

  racine.querySelector('[data-role="volumes"]').replaceChildren(
    carteKpi('SOC-ECRITURES', { ton: 'accent' }),
    carteKpi('SOC-FACTURES'),
    carteKpi('SOC-VIREMENTS'),
    carteKpi('SOC-VIREMENTS-MONTANT', { ton: 'or' }),
    carteKpi('SOC-REMBOURSEMENTS'),
    carteKpi('SOC-DOSSIERS-LIVRAISON'),
    carteKpi('SOC-COMITES'),
    carteKpi('SOC-RELANCE-PORTEFEUILLE'),
  );

  racine.querySelector('[data-role="difficulte"]').replaceChildren(
    carteKpi('SOC-SANS-REFERENCE', { ton: 'rouge' }),
    carteKpi('SOC-CODES-DIVERGENTS', { ton: 'rouge' }),
    carteKpi('SOC-NON-LETTRE', { ton: 'rouge' }),
    carteKpi('SOC-MONTANT-NON-LETTRE', { ton: 'or' }),
    carteKpi('SOC-ENCOURS'),
    carteKpi('SOC-BUYBACK'),
  );

  // Agregations de comite, calculees sur les lignes.
  const comites = await table('ops_decision_comite');
  const parFamille = repartir(comites, (d) => FAMILLES_LIB[d.famille] || d.famille, (d) => d.montant);
  racine.querySelector('[data-role="familles"]').replaceChildren(barres(parFamille, { format: euroCourt }));

  const etabs = await table('ref_etablissement');
  const nomEtab = new Map(etabs.map((e) => [e.id, e.nom]));
  const parEtab = repartir(comites, (d) => nomEtab.get(d.etablissement_id) || d.etablissement_id, (d) => d.montant).slice(0, 10);
  racine.querySelector('[data-role="concentration"]').replaceChildren(barres(parEtab, { format: euroCourt }));

  const totalMontant = somme(comites, (d) => d.montant);
  const huitPremiers = repartir(comites, 'etablissement_id', (d) => d.montant).slice(0, 8);
  const partHuit = somme(huitPremiers, (x) => x.valeur) / totalMontant;
  racine.querySelector('[data-role="comite-kpi"]').replaceChildren(
    carteKpi('SOC-COMITES-SOLVABILITE', { ton: 'vert' }),
    el(`<div class="kpi" style="cursor:default">
      <span class="kpi__lib">Part des montants portée par les huit établissements les plus exposés</span>
      <span class="kpi__val" style="color:var(--or)">${pourcent(partHuit)}</span>
      <span class="kpi__pied"><span>${nb(comites.length)} décisions · calculé à l'affichage</span><span></span></span></div>`),
    el(`<div class="kpi" style="cursor:default">
      <span class="kpi__lib">Motifs distincts employés en séance</span>
      <span class="kpi__val">${nb(new Set(comites.map((d) => d.motif)).size)}</span>
      <span class="kpi__pied"><span>sur une nomenclature de 42</span><span></span></span></div>`),
  );

  // La verite : on n'ouvre que maintenant.
  const zone = racine.querySelector('[data-role="verite"]');
  const bouton = el('<button type="button" class="btn btn--prim" style="margin-top:.8rem">Ouvrir la vérité de référence et mesurer</button>');
  zone.replaceChildren(bouton);
  bouton.addEventListener('click', async () => {
    bouton.disabled = true;
    bouton.textContent = 'Chargement du paquet de vérité…';
    const t0 = performance.now();
    const [vVir, vRbc, vRel, vDlv] = await Promise.all([
      verite('truth_virement'), verite('truth_remboursement'),
      verite('truth_relance'), verite('truth_livraison'),
    ]);
    const ms = performance.now() - t0;

    const bloc = (titre, lignes, cle, total) => {
      const r = repartir(lignes, cle);
      return `<div class="carte" style="box-shadow:none">
        <div class="carte__titre"><h3>${echapper(titre)}</h3><small>${nb(total)} objets</small></div>
        <table class="tab"><tbody>${r.map((x) => `<tr>
          <td>${echapper(String(x.cle ?? '—'))}</td>
          <td class="num">${nb(x.valeur)}</td>
          <td class="num">${pourcent(x.valeur / total)}</td></tr>`).join('')}</tbody></table></div>`;
    };

    zone.replaceChildren(el(`<div>
      <p class="mesure">Paquet de vérité ouvert en ${ms.toFixed(0)} ms, mesuré sur cet appareil.
        ${nb(vVir.length + vRbc.length + vRel.length + vDlv.length)} réponses de référence chargées.</p>
      <div class="grille g2" style="margin-top:.8rem">
        ${bloc('Virements : décision attendue', vVir, 'decision_attendue', vVir.length)}
        ${bloc('Remboursements : décision attendue', vRbc, 'decision_attendue', vRbc.length)}
        ${bloc('Relance : faut-il contacter ?', vRel, (l) => (l.doit_etre_contacte ? 'oui, à relancer' : `non — ${l.cause_exclusion}`), vRel.length)}
        ${bloc('Livraison : décision attendue', vDlv, 'decision_attendue', vDlv.length)}
      </div>
      <p class="note note--info" style="margin-top:1rem"><b>Ce que cet écran deviendra.</b>
        Dès qu'un moteur est branché, ses décisions sont confrontées ligne à ligne à cette
        référence, et la matrice de confusion s'affiche ici : précision, rappel, faux positifs,
        faux négatifs, montant arrêté à raison et montant arrêté à tort. Le tableau ci-dessus
        montre déjà que <b>la bonne réponse existe et qu'elle est séparée</b>.</p>
      <p class="mesure">Séparation vérifiée : les moteurs reçoivent le paquet « monde » ;
        ce paquet « vérité » vit dans un dossier distinct et n'est ouvert que par cet écran.</p>
    </div>`));
  });

  return racine;
}
