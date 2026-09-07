import { Controller } from '@hotwired/stimulus';

/*
 * "Mes dossiers" (secretaire) : met a jour EN DIRECT le badge d'etat d'une ligne
 * quand le statut du dossier change cote serveur (worker IA, comptable,
 * directeur), sans rechargement. Ecoute l'evenement rediffuse par le canal
 * Mercure mutualise (controleur "realtime" du layout : une seule connexion par
 * onglet). Le badge deja rendu (source unique = la macro Twig) est envoye dans
 * l'evenement -> on remplace simplement le contenu de la cellule.
 */
export default class extends Controller {
    connect() {
        this.onDossier = (e) => this.majBadge(e.detail);
        document.addEventListener('remboursement:dossier', this.onDossier);
    }

    disconnect() {
        document.removeEventListener('remboursement:dossier', this.onDossier);
    }

    majBadge(data) {
        if (!data || undefined === data.id || !data.badge) {
            return;
        }
        const cellule = this.element.querySelector(
            `tr[data-dossier-id="${data.id}"] [data-dossier-badge]`,
        );
        if (cellule) {
            cellule.innerHTML = data.badge;
        }
    }

    // Clic sur un dossier « correction requise » : on ouvre la fiche EDITABLE sans
    // rechargement (on remplace le contenu de <main>, on met a jour l'URL et on remonte
    // en haut). Retour navigateur -> rechargement de la liste. Repli : navigation directe.
    async ouvrirCorrection(event) {
        const url = event.currentTarget.dataset.url;
        if (!url) {
            return;
        }
        try {
            const reponse = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!reponse.ok) {
                window.location = url;

                return;
            }
            const doc = new DOMParser().parseFromString(await reponse.text(), 'text/html');
            const main = document.querySelector('main');
            const nouveau = doc.querySelector('main');
            if (!main || !nouveau) {
                window.location = url;

                return;
            }
            if (!window.__rembSoftnavPop) {
                window.__rembSoftnavPop = true;
                window.addEventListener('popstate', () => window.location.reload());
            }
            main.innerHTML = nouveau.innerHTML;
            if (doc.title) {
                document.title = doc.title;
            }
            window.history.pushState({ rembSoftnav: true }, '', url);
            window.scrollTo({ top: 0, behavior: 'auto' });
        } catch (e) {
            window.location = url;
        }
    }
}
