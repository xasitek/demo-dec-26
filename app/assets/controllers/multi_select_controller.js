import { Controller } from '@hotwired/stimulus';

/*
 * Multi-select : bouton + dropdown a checkboxes. Le label montre les
 * valeurs cochees (1-2 valeurs : intitule ; >2 : "N <count_label>").
 * A la fermeture du dropdown (clic dehors, Echap, ou re-clic bouton),
 * si la selection a change, le formulaire parent est soumis.
 *
 * Valeurs : placeholder (texte quand rien de coche), countLabel
 * ("marques" / "concessions" / etc.).
 */
export default class extends Controller {
    static targets = ['label', 'menu', 'checkbox', 'search', 'option', 'empty', 'optionsContainer'];
    static values = { placeholder: String, countLabel: String };

    connect() {
        this.outsideHandler = (e) => {
            if (!this.element.contains(e.target)) this.close();
        };
        this.escHandler = (e) => {
            if ('Escape' === e.key) this.close();
        };
        // Coordination inter-instances : quand un autre multi-select s'ouvre,
        // celui-ci se ferme.
        this.otherOpenedHandler = (e) => {
            if (e.detail?.source !== this.element) this.close();
        };
        document.addEventListener('multiselect:opened', this.otherOpenedHandler);
        this.refreshLabel();
    }

    disconnect() {
        document.removeEventListener('click', this.outsideHandler);
        document.removeEventListener('keydown', this.escHandler);
        document.removeEventListener('multiselect:opened', this.otherOpenedHandler);
        clearTimeout(this.submitTimer);
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();
        if (this.isOpen()) this.close(); else this.open();
    }

    isOpen() {
        return !this.menuTarget.hidden;
    }

    open() {
        if (this.isOpen()) return;
        // Ferme les autres dropdowns avant d'ouvrir celui-ci.
        document.dispatchEvent(new CustomEvent('multiselect:opened', { detail: { source: this.element } }));
        this.menuTarget.hidden = false;
        // setTimeout pour eviter que le click qui a ouvert ne soit aussi
        // capture comme "click outside" par le listener qu'on installe.
        setTimeout(() => {
            document.addEventListener('click', this.outsideHandler);
            document.addEventListener('keydown', this.escHandler);
            // Focus le champ de recherche si present (>5 options : visible).
            if (this.hasSearchTarget) this.searchTarget.focus();
        }, 0);
    }

    close() {
        if (!this.isOpen()) return;
        this.menuTarget.hidden = true;
        document.removeEventListener('click', this.outsideHandler);
        document.removeEventListener('keydown', this.escHandler);
        // Reset la recherche : a la prochaine ouverture, toutes les options sont visibles.
        if (this.hasSearchTarget && '' !== this.searchTarget.value) {
            this.searchTarget.value = '';
            this.filter();
        }
    }

    filter() {
        if (!this.hasSearchTarget) return;
        const q = this.searchTarget.value.trim().toLowerCase();
        let visibles = 0;
        this.optionTargets.forEach((opt) => {
            const texte = opt.dataset.labelText || '';
            const match = '' === q || texte.includes(q);
            opt.hidden = !match;
            if (match) visibles += 1;
        });
        if (this.hasEmptyTarget) this.emptyTarget.hidden = visibles > 0;
    }

    searchKey(event) {
        // Empeche Entree de soumettre le formulaire parent : l'utilisateur
        // valide en cochant, pas en tapant Entree.
        if ('Enter' === event.key) {
            event.preventDefault();
        }
    }

    update() {
        this.refreshLabel();
        // Recherche instantanee : submit a chaque change, avec un mini-debounce
        // pour batcher les coches rapides en une seule requete. requestSubmit()
        // (et non submit()) pour que Turbo intercepte et mette a jour seulement
        // le frame, sans recharger la page ni fermer le dropdown.
        clearTimeout(this.submitTimer);
        this.submitTimer = setTimeout(() => {
            // 1) form parent direct (cas habituel : multi-select dans un <form>)
            // 2) sinon : attribut form="id" des checkboxes (cas du multi-select
            //    place dans un <th> hors du <form>, ex. filtres de colonnes)
            let form = this.element.closest('form');
            if (!form && this.checkboxTargets.length > 0) {
                const formId = this.checkboxTargets[0].getAttribute('form');
                if (formId) form = document.getElementById(formId);
            }
            if (form) form.requestSubmit();
        }, 200);
    }

    refreshLabel() {
        const checked = this.checkboxTargets.filter((c) => c.checked);
        if (0 === checked.length) {
            this.labelTarget.textContent = this.placeholderValue;
            this.element.classList.remove('is-active');
            return;
        }
        this.element.classList.add('is-active');
        if (checked.length <= 2) {
            this.labelTarget.textContent = checked.map((c) => c.dataset.label || c.value).join(', ');
            return;
        }
        this.labelTarget.textContent = `${checked.length} ${this.countLabelValue}`;
    }
}
