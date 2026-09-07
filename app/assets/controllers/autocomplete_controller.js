import { Controller } from '@hotwired/stimulus';

/*
 * Autocompletion custom : affiche un menu de suggestions stylise sous un champ
 * texte, filtre selon la saisie. Les suggestions sont fournies via la valeur
 * "items" (tableau JSON). Reutilisable partout.
 */
export default class extends Controller {
    static targets = ['input', 'menu'];
    static values = { items: Array, max: { type: Number, default: 8 }, ouvrirVide: Boolean };

    connect() {
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
    }

    filter() {
        const query = this.inputTarget.value.trim().toLowerCase();
        this.menuTarget.innerHTML = '';

        // Champ vide : par défaut on ne propose rien — l'écran d'administration veut une
        // recherche, pas un menu qui s'ouvre au moindre focus. Avec `ouvrir-vide`, le
        // focus déroule au contraire la liste entière : c'est ce qui transforme le champ
        // en « select + autocomplétion ».
        if ('' === query && !this.ouvrirVideValue) {
            this.close();
            return;
        }

        const matches = this.itemsValue
            .filter((item) => '' === query || item.toLowerCase().includes(query))
            .slice(0, this.maxValue);

        if (0 === matches.length) {
            this.close();
            return;
        }

        matches.forEach((match) => {
            const item = document.createElement('li');
            item.className = 'fc-ac-option';
            item.textContent = match;
            // mousedown plutot que click : se declenche avant le blur du champ.
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.choose(match);
            });
            this.menuTarget.appendChild(item);
        });

        this.menuTarget.hidden = false;
    }

    choose(value) {
        this.inputTarget.value = value;
        this.close();
        this.inputTarget.focus();
    }

    close() {
        this.menuTarget.hidden = true;
    }
}
