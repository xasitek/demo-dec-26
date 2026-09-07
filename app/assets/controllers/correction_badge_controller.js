import { Controller } from '@hotwired/stimulus';

/*
 * Badge « à corriger » de la nav secrétaire : met à jour EN DIRECT le nombre de dossiers
 * en correction requise. Écoute l'événement rediffusé par le canal Mercure mutualisé du
 * layout (topic privé de la secrétaire) ; le message porte le compteur recalculé côté
 * serveur à chaque changement de statut d'un de ses dossiers.
 */
export default class extends Controller {
    static targets = ['compteur'];

    connect() {
        this.onDossier = (e) => this.maj(e.detail);
        document.addEventListener('remboursement:dossier', this.onDossier);
    }

    disconnect() {
        document.removeEventListener('remboursement:dossier', this.onDossier);
    }

    maj(data) {
        if (!data || undefined === data.nbCorrection || !this.hasCompteurTarget) {
            return;
        }
        const n = Number(data.nbCorrection);
        this.compteurTarget.textContent = n > 99 ? '99+' : String(n);
        this.compteurTarget.classList.toggle('hidden', !(n > 0));
    }
}
