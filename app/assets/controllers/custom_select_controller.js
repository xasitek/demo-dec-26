import { Controller } from '@hotwired/stimulus';

/*
 * Select custom : remplace le rendu natif d'un <select> par un menu stylise,
 * tout en gardant le <select> natif (cache) pour la valeur et la soumission.
 * Reutilisable partout.
 */
export default class extends Controller {
    static targets = ['native', 'button', 'menu', 'label'];

    connect() {
        this.buildMenu();
        this.syncLabel();
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

    buildMenu() {
        this.menuTarget.innerHTML = '';
        Array.from(this.nativeTarget.options).forEach((option) => {
            const item = document.createElement('li');
            item.className = 'fc-select-option';
            item.dataset.value = option.value;
            if (option.dataset.color) {
                item.appendChild(this.dot(option.dataset.color));
            }
            item.appendChild(document.createTextNode(option.textContent));
            if (option.selected) {
                item.classList.add('is-selected');
            }
            item.addEventListener('click', () => this.choose(option.value, option.textContent));
            this.menuTarget.appendChild(item);
        });
    }

    // Pastille de couleur (badge d'etat) pour une option coloree.
    dot(color) {
        const dot = document.createElement('span');
        dot.className = 'fc-select-dot';
        dot.style.background = color;

        return dot;
    }

    // Libelle du bouton : pastille (si couleur) + texte.
    setLabel(text, color) {
        if (!this.hasLabelTarget) {
            return;
        }
        this.labelTarget.textContent = '';
        if (color) {
            this.labelTarget.appendChild(this.dot(color));
        }
        this.labelTarget.appendChild(document.createTextNode(text));
    }

    toggle() {
        this.menuTarget.hidden = !this.menuTarget.hidden;
    }

    close() {
        this.menuTarget.hidden = true;
    }

    syncLabel() {
        const selected = this.nativeTarget.options[this.nativeTarget.selectedIndex];
        if (selected) {
            this.setLabel(selected.textContent, selected.dataset.color);
        }
    }

    choose(value, label) {
        this.nativeTarget.value = value;
        const option = Array.from(this.nativeTarget.options).find((o) => o.value === value);
        this.setLabel(label, option ? option.dataset.color : undefined);
        this.menuTarget.querySelectorAll('.fc-select-option').forEach((item) => {
            item.classList.toggle('is-selected', item.dataset.value === value);
        });
        this.close();
        this.nativeTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    submit() {
        const form = this.element.closest('form');
        if (form) {
            form.requestSubmit();
        }
    }
}
