import { Controller } from '@hotwired/stimulus';

/**
 * Onglets cote client pour la fiche tiers Recouvrement.
 *
 * Markup attendu :
 *   <div data-controller="creances-tabs">
 *     <nav>
 *       <button data-creances-tabs-target="tab" data-key="ecritures">...</button>
 *       <button data-creances-tabs-target="tab" data-key="actions">...</button>
 *       ...
 *     </nav>
 *     <section data-creances-tabs-target="panel" data-key="ecritures">...</section>
 *     <section data-creances-tabs-target="panel" data-key="actions">...</section>
 *     ...
 *   </div>
 *
 * L'onglet par defaut est lu dans `data-creances-tabs-default-value` ou est
 * le premier bouton. Persistance basique via hash URL (#tab=actions).
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { default: { type: String, default: '' } };

    connect() {
        const hashMatch = window.location.hash.match(/tab=([\w-]+)/);
        const initial = hashMatch ? hashMatch[1] : (this.defaultValue || this.tabTargets[0]?.dataset.key);
        if (initial) {
            this.activer(initial);
        }
    }

    select(event) {
        const key = event.currentTarget.dataset.key;
        if (!key) return;
        this.activer(key);
        // Mise a jour de l'URL (sans push, replace) pour permettre le partage de lien direct.
        const url = new URL(window.location.href);
        url.hash = `tab=${key}`;
        window.history.replaceState({}, '', url);
    }

    activer(key) {
        this.tabTargets.forEach((btn) => {
            const actif = btn.dataset.key === key;
            btn.classList.toggle('is-active', actif);
            btn.setAttribute('aria-selected', actif ? 'true' : 'false');
        });
        this.panelTargets.forEach((p) => {
            p.hidden = p.dataset.key !== key;
        });
    }
}
