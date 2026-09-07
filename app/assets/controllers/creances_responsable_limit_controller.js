import { Controller } from '@hotwired/stimulus';

/**
 * Segmented control "Top 5 / 10 / 20 / Tout" pour les blocs Performance par
 * responsable (page Analyses Recouvrement).
 *
 * Markup attendu :
 *   <div data-controller="creances-responsable-limit"
 *        data-creances-responsable-limit-limit-value="10">
 *     <header>
 *       <div data-creances-responsable-limit-target="track">
 *         <button data-creances-responsable-limit-target="button"
 *                 data-action="click->creances-responsable-limit#set"
 *                 data-limit="5">5</button>
 *         <button ... data-limit="10">10</button>
 *         <button ... data-limit="20">20</button>
 *         <button ... data-limit="all">Tout</button>
 *         <span data-creances-responsable-limit-target="slider"></span>
 *       </div>
 *     </header>
 *     <ul>
 *       <li data-creances-responsable-limit-target="row">...</li>
 *       ...
 *     </ul>
 *   </div>
 *
 * Le `<span data-target="slider">` est positionne via JS pour glisser sous
 * le bouton actif (effet visuel "curseur deplace").
 */
export default class extends Controller {
    static targets = ['row', 'button', 'slider', 'compteur'];
    static values = { limit: { type: String, default: '10' } };

    connect() {
        this.appliquer(this.limitValue, false);
    }

    set(event) {
        const limit = event.currentTarget.dataset.limit;
        if (limit) {
            this.appliquer(limit, true);
        }
    }

    appliquer(limit, anime) {
        const max = limit === 'all' ? Infinity : parseInt(limit, 10);
        let visibles = 0;
        this.rowTargets.forEach((row, idx) => {
            const visible = idx < max;
            row.hidden = !visible;
            if (visible) {
                visibles += 1;
            }
        });
        this.buttonTargets.forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.limit === limit);
        });
        if (this.hasCompteurTarget) {
            this.compteurTarget.textContent = visibles;
        }
        this.positionnerSlider(limit, anime);
        this.limitValue = limit;
    }

    positionnerSlider(limit, anime) {
        if (!this.hasSliderTarget) return;
        const actif = this.buttonTargets.find((b) => b.dataset.limit === limit);
        if (!actif) return;
        if (!anime) {
            // Premiere passe : on supprime la transition pour ne pas glisser
            // depuis l'origine 0,0 au chargement.
            this.sliderTarget.style.transition = 'none';
        }
        this.sliderTarget.style.left = `${actif.offsetLeft}px`;
        this.sliderTarget.style.width = `${actif.offsetWidth}px`;
        if (!anime) {
            // Force reflow puis restaure la transition.
            // eslint-disable-next-line no-unused-expressions
            this.sliderTarget.offsetWidth;
            this.sliderTarget.style.transition = '';
        }
    }
}
