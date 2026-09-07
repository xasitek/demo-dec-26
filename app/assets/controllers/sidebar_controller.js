import { Controller } from '@hotwired/stimulus';

/*
 * Ouvre/ferme la barre laterale sur mobile (classe is-open).
 * Sur desktop, la barre s'etend au survol via le CSS.
 */
export default class extends Controller {
    static targets = ['panel', 'overlay'];

    open() {
        this.panelTarget.classList.add('is-open');
        if (this.hasOverlayTarget) {
            this.overlayTarget.classList.remove('hidden');
        }
    }

    close() {
        this.panelTarget.classList.remove('is-open');
        if (this.hasOverlayTarget) {
            this.overlayTarget.classList.add('hidden');
        }
    }
}
