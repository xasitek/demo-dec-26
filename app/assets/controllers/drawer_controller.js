import { Controller } from '@hotwired/stimulus';

/*
 * Panneau lateral coulissant (ex. notifications). Ouvre/ferme avec animation,
 * ferme au clic sur le fond (backdrop) ou via la touche Echap.
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop'];

    connect() {
        this.onKey = (event) => {
            if ('Escape' === event.key) {
                this.close();
            }
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    open() {
        this.toggle(true);
    }

    close() {
        this.toggle(false);
    }

    toggle(show) {
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.toggle('hidden', !show);
        }
        if (this.hasPanelTarget) {
            this.panelTarget.classList.toggle('translate-x-full', !show);
        }
    }
}
