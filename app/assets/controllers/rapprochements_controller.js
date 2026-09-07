import { Controller } from '@hotwired/stimulus';

/*
 * Panneau des rapprochements de l'ecran de verification (module Remboursement).
 *
 * Les controles qui signalaient un risque de double paiement occupaient chacun un
 * bandeau pleine largeur ; ils tiennent maintenant dans le compteur ambre de la ligne
 * concernee. Un clic sur ce compteur ouvre ce panneau sur la section correspondante.
 *
 *   <button data-action="rapprochements#ouvrir"
 *           data-rapprochements-section-param="cle|iban">
 *
 * Aucun appel reseau : les sections sont deja rendues dans la page (masquees), parce
 * que leurs donnees sont deja calculees pour l'ecran. L'ouverture est donc immediate.
 * Fermeture par la croix, le fond, ou Echap.
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'section', 'titre'];

    connect() {
        this.onKey = (event) => {
            if ('Escape' === event.key) this.fermer();
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    ouvrir(event) {
        const section = event.params?.section;
        if (!section) return;

        let trouvee = false;
        this.sectionTargets.forEach((el) => {
            const visible = el.dataset.section === section;
            el.hidden = !visible;
            if (visible) trouvee = true;
        });
        if (!trouvee) return;

        // Le titre reprend celui de la section ouverte : le panneau dit de quel
        // rapprochement il parle, meme ouvert depuis une autre ligne.
        if (this.hasTitreTarget) {
            const titre = this.sectionTargets.find((el) => el.dataset.section === section)?.dataset.titre;
            if (titre) this.titreTarget.textContent = titre;
        }

        if (this.hasBackdropTarget) this.backdropTarget.hidden = false;
        this.panelTarget.classList.remove('translate-x-full');
    }

    fermer() {
        if (!this.hasPanelTarget || this.panelTarget.classList.contains('translate-x-full')) return;
        this.panelTarget.classList.add('translate-x-full');
        if (this.hasBackdropTarget) this.backdropTarget.hidden = true;
    }
}
