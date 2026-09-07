import { Controller } from '@hotwired/stimulus';

/*
 * Onglet "A verifier" : insertion EN DIRECT d'un dossier qui arrive dans la file
 * (evenement rediffuse par le canal Mercure mutualise du layout), compteur, titre
 * d'onglet, et bascule entre le message "tout est verifie" (vide) et la table
 * (section) selon qu'il reste ou non des dossiers.
 */
export default class extends Controller {
    static targets = ['rows', 'compteur', 'section', 'vide'];
    static values = { ligneUrl: String };

    connect() {
        this.onArrive = (e) => this.inserer(e.detail);
        document.addEventListener('remboursement:a-verifier', this.onArrive);
        this.majTitre();
        this.majVisibilite();
    }

    disconnect() {
        document.removeEventListener('remboursement:a-verifier', this.onArrive);
    }

    async inserer(data) {
        if (!data || undefined === data.id || !this.hasRowsTarget || !this.ligneUrlValue) {
            return;
        }
        if (this.rowsTarget.querySelector(`tr[data-dossier-id="${data.id}"]`)) {
            return; // déjà présent
        }
        try {
            const url = this.ligneUrlValue.replace('__ID__', data.id);
            const reponse = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!reponse.ok) {
                return;
            }
            const html = (await reponse.text()).trim();
            if ('' === html) {
                return;
            }
            this.rowsTarget.insertAdjacentHTML('afterbegin', html);
            const ligne = this.rowsTarget.querySelector(`tr[data-dossier-id="${data.id}"]`);
            if (ligne) {
                ligne.classList.add('bg-gold/10');
                setTimeout(() => ligne.classList.remove('bg-gold/10'), 4000);
            }
            this.majCompteur();
        } catch (e) {
            // silencieux
        }
    }

    majCompteur() {
        if (this.hasCompteurTarget && this.hasRowsTarget) {
            this.compteurTarget.textContent = this.rowsTarget.querySelectorAll('tr[data-dossier-id]').length;
        }
        this.majTitre();
        this.majVisibilite();
    }

    // Titre d'onglet : "(N) Remboursement client" quand il reste des dossiers a verifier.
    majTitre() {
        if (!this.hasRowsTarget) {
            return;
        }
        const n = this.rowsTarget.querySelectorAll('tr[data-dossier-id]').length;
        document.title = (n > 0 ? `(${n}) ` : '') + 'Remboursement client';
    }

    // Table visible s'il reste des dossiers, sinon message "tout est verifie".
    majVisibilite() {
        const n = this.hasRowsTarget ? this.rowsTarget.querySelectorAll('tr[data-dossier-id]').length : 0;
        if (this.hasSectionTarget) {
            this.sectionTarget.classList.toggle('hidden', 0 === n);
        }
        if (this.hasVideTarget) {
            this.videTarget.classList.toggle('hidden', n > 0);
        }
    }
}
