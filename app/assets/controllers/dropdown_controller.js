import { Controller } from '@hotwired/stimulus';

/*
 * Menu deroulant generique (ex : menu profil). Ferme au clic exterieur.
 */
export default class extends Controller {
    static targets = ['menu'];

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

    toggle() {
        this.menuTarget.hidden = !this.menuTarget.hidden;
    }

    close() {
        this.menuTarget.hidden = true;
    }
}
