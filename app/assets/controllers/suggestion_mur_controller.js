import { Controller } from '@hotwired/stimulus';

/*
 * Mur des idees : soutien (vote) sans rechargement, et mise a jour temps reel.
 *
 * Le flux Mercure est mutualise par realtime_controller (une seule connexion par
 * onglet, cf. docs/REALTIME.md) : on ecoute ici l'evenement `suggestions:maj`
 * qu'il rediffuse.
 */
export default class extends Controller {
    static targets = ['bouton', 'compteur', 'bandeau', 'liste'];

    connect() {
        this.onMaj = (event) => this.appliquer(event.detail);
        document.addEventListener('suggestions:maj', this.onMaj);
    }

    disconnect() {
        document.removeEventListener('suggestions:maj', this.onMaj);
    }

    async voter(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const bouton = form.querySelector('button');
        if (bouton.disabled) {
            return;
        }
        bouton.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok && data.ok) {
                this.majBouton(bouton, data.vote);
                this.majCompteur(form.dataset.id, data.votes);
            }
        } catch (error) {
            // Echec silencieux : l'etat affiche reste celui du serveur.
        } finally {
            bouton.disabled = false;
        }
    }

    /* Evenement Mercure : nouvelle idee ailleurs, ou compteur de votes qui bouge. */
    appliquer(detail) {
        if (!detail) {
            return;
        }

        if ('vote' === detail.action) {
            this.majCompteur(String(detail.id), detail.votes);
            return;
        }

        if ('nouvelle' === detail.action && this.hasBandeauTarget) {
            this.bandeauTarget.hidden = false;
        }
    }

    actualiser() {
        window.location.reload();
    }

    majCompteur(id, votes) {
        if (undefined === votes) {
            return;
        }

        const cible = this.compteurTargets.find((noeud) => noeud.dataset.id === String(id));
        if (cible) {
            cible.textContent = votes;
        }
    }

    majBouton(bouton, vote) {
        const actives = ['border-gold', 'bg-gold/10', 'text-gold-dark'];
        const inactives = ['border-hairline', 'text-ink/55'];

        actives.forEach((classe) => bouton.classList.toggle(classe, vote));
        inactives.forEach((classe) => bouton.classList.toggle(classe, !vote));

        const libelle = vote ? 'Retirer mon soutien' : 'Soutenir cette idée';
        bouton.setAttribute('aria-label', libelle);
        bouton.setAttribute('title', libelle);
    }
}
