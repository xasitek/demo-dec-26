import { Controller } from '@hotwired/stimulus';

/*
 * Onglets de l'aperçu comptable : bascule la pièce affichée dans la visionneuse
 * (iframe : PDF ou image). L'onglet actif est marqué via aria-pressed (stylé côté
 * Tailwind). Le panneau est réinjecté à chaque clic de ligne -> Stimulus reconnecte
 * ce contrôleur automatiquement.
 */
export default class extends Controller {
    static targets = ['frame'];

    afficher(event) {
        const bouton = event.currentTarget;
        const src = bouton.dataset.src;
        if (src && this.hasFrameTarget) {
            this.frameTarget.src = src;
        }
        this.element
            .querySelectorAll('[aria-pressed]')
            .forEach((b) => b.setAttribute('aria-pressed', 'false'));
        bouton.setAttribute('aria-pressed', 'true');
    }
}
