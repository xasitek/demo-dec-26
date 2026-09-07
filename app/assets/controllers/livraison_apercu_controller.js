import { Controller } from '@hotwired/stimulus';

/**
 * Aperçu des pièces d'une déclaration de livraison, dans le volet de droite de
 * l'écran de contrôle.
 *
 * La comptable enchaîne PV, bon de commande et CPI sur le même dossier : recharger
 * la page à chaque pièce lui ferait perdre sa place et son défilement. L'iframe
 * évite aussi de télécharger les fichiers, qui restent servis en ligne.
 */
export default class extends Controller {
    static targets = ['cadre', 'titre', 'vide', 'lien'];

    ouvrir(event) {
        const { url, titre } = event.params;
        if (!url) {
            return;
        }

        this.cadreTarget.src = url;
        this.cadreTarget.hidden = false;
        this.videTarget.hidden = true;

        if (this.hasTitreTarget) {
            this.titreTarget.textContent = titre ?? 'Aperçu';
        }
        if (this.hasLienTarget) {
            this.lienTarget.href = url;
            this.lienTarget.hidden = false;
        }

        // La pièce active se distingue des autres boutons du groupe.
        this.element.querySelectorAll('[data-action*="livraison-apercu#ouvrir"]').forEach((bouton) => {
            const actif = bouton === event.currentTarget;
            bouton.classList.toggle('border-navy', actif);
            bouton.classList.toggle('text-navy', actif);
            bouton.classList.toggle('bg-navy/[0.04]', actif);
        });
    }
}
