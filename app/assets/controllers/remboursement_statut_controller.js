import { Controller } from '@hotwired/stimulus';

/*
 * Met a jour EN DIRECT le badge de statut d'un dossier partout dans /remboursement
 * (onglets "A verifier" et "Suivi dossier"), sans rechargement. Ecoute l'evenement
 * rediffuse par le canal Mercure mutualise du layout (controleur "realtime").
 */
export default class extends Controller {
    connect() {
        this.onStatut = (e) => this.majStatut(e.detail);
        document.addEventListener('remboursement:statut', this.onStatut);
        // Badge de l'onglet "A verifier" (topbar) : incremente en direct a chaque
        // nouveau dossier qui arrive en verification comptable.
        this.vus = new Set();
        this.onAVerifier = (e) => this.incrementerOnglet(e.detail);
        document.addEventListener('remboursement:a-verifier', this.onAVerifier);
    }

    disconnect() {
        document.removeEventListener('remboursement:statut', this.onStatut);
        document.removeEventListener('remboursement:a-verifier', this.onAVerifier);
    }

    incrementerOnglet(data) {
        if (!data || undefined === data.id || this.vus.has(String(data.id))) {
            return;
        }
        this.vus.add(String(data.id));
        const badge = document.querySelector('[data-remb-nav-compteur]');
        if (!badge) {
            return;
        }
        const n = (parseInt(badge.dataset.compte || '0', 10) || 0) + 1;
        badge.dataset.compte = String(n);
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.classList.remove('hidden');
    }

    majStatut(data) {
        if (!data || undefined === data.id || !data.badge) {
            return;
        }
        document
            .querySelectorAll(`tr[data-dossier-id="${data.id}"] [data-dossier-badge]`)
            .forEach((cellule) => {
                cellule.innerHTML = data.badge;
            });
    }
}
