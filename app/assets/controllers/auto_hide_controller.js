import { Controller } from '@hotwired/stimulus';

/**
 * Masque automatiquement l'element apres un delai (ms).
 *
 *   <div data-controller="auto-hide" data-auto-hide-delay-value="5000">...</div>
 *
 * Le fade out dure 300ms avant suppression definitive du DOM.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 5000 } };

    connect() {
        this.timeout = window.setTimeout(() => this.fadeOut(), this.delayValue);
    }

    disconnect() {
        if (this.timeout) window.clearTimeout(this.timeout);
    }

    fadeOut() {
        this.element.style.transition = 'opacity 300ms ease-out, max-height 300ms ease-out, margin 300ms ease-out, padding 300ms ease-out';
        this.element.style.opacity = '0';
        this.element.style.maxHeight = '0';
        this.element.style.marginTop = '0';
        this.element.style.marginBottom = '0';
        this.element.style.paddingTop = '0';
        this.element.style.paddingBottom = '0';
        this.element.style.overflow = 'hidden';
        window.setTimeout(() => this.element.remove(), 320);
    }
}
