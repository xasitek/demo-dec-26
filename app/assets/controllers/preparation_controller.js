import { Controller } from '@hotwired/stimulus';

/*
 * Barre de progression GLOBALE d'un lancement manuel de strategie (bouton
 * "Lancer maintenant" de la page Strategies). Persistante : presente dans le
 * layout, donc visible sur toutes les pages du module, y compris apres un
 * changement de page.
 *
 * Deux sources d'alimentation :
 *   1. au chargement : un fetch de l'etat courant (un run est-il en cours ?),
 *      pour reafficher la barre si l'utilisateur navigue pendant un lancement ;
 *   2. en direct : l'evenement DOM "recouvrement:preparation" rediffuse par le
 *      canal Mercure mutualise (realtime_controller) — une seule connexion/onglet.
 */
const DELAI_MASQUAGE_MS = 6000;

export default class extends Controller {
    static targets = ['card', 'bar', 'title', 'detail', 'spinner', 'check', 'close'];
    static values = { etatUrl: String };

    connect() {
        this.onEvent = (e) => this.render(e.detail || {});
        document.addEventListener('recouvrement:preparation', this.onEvent);
        this.chargerEtat();
    }

    disconnect() {
        document.removeEventListener('recouvrement:preparation', this.onEvent);
        clearTimeout(this.hideTimer);
    }

    async chargerEtat() {
        if (!this.hasEtatUrlValue) {
            return;
        }
        try {
            const reponse = await fetch(this.etatUrlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (reponse.ok) {
                this.render(await reponse.json());
            }
        } catch (e) {
            // silencieux : la barre restera masquee
        }
    }

    render(data) {
        const enCours = true === data.en_cours || 'en_cours' === data.statut;
        const termine = true === data.termine || (data.statut && 'en_cours' !== data.statut);

        if (!enCours && !termine) {
            this.masquer();
            return;
        }

        clearTimeout(this.hideTimer);
        this.afficher();

        const pct = 'number' === typeof data.pct ? Math.max(0, Math.min(100, data.pct)) : 0;
        if (this.hasBarTarget) {
            this.barTarget.style.width = `${pct}%`;
        }
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = data.regle_nom ? `Lancement — ${data.regle_nom}` : 'Lancement en cours';
        }

        if (termine) {
            this.marquerFini('echec' === data.statut, Number(data.traites) || 0);
        } else {
            this.marquerEnCours(Number(data.traites) || 0, Number(data.total) || 0);
        }
    }

    marquerEnCours(traites, total) {
        this.basculerIcones(false);
        if (this.hasCloseTarget) {
            this.closeTarget.classList.add('hidden');
        }
        if (this.hasBarTarget) {
            this.barTarget.classList.remove('bg-positive', 'bg-negative');
            this.barTarget.classList.add('bg-navy');
        }
        if (this.hasDetailTarget) {
            this.detailTarget.textContent = total > 0
                ? `${traites} / ${total} comptes`
                : 'Préparation…';
        }
    }

    marquerFini(echec, traites) {
        this.basculerIcones(true, echec);
        if (this.hasCloseTarget) {
            this.closeTarget.classList.remove('hidden');
        }
        if (this.hasBarTarget) {
            this.barTarget.style.width = '100%';
            this.barTarget.classList.remove('bg-navy');
            this.barTarget.classList.add(echec ? 'bg-negative' : 'bg-positive');
        }
        if (this.hasDetailTarget) {
            this.detailTarget.textContent = echec
                ? 'Échec du lancement'
                : `Terminé — ${traites} compte(s) traité(s)`;
        }
        // Si on est reste sur la page Strategies, les boutons "Lancer" avaient ete
        // desactives le temps du run : on les reactive (sinon grises jusqu'au reload).
        this.reactiverBoutonsLancer();
        // Auto-masquage apres quelques secondes (l'utilisateur peut fermer avant).
        this.hideTimer = setTimeout(() => this.masquer(), DELAI_MASQUAGE_MS);
    }

    reactiverBoutonsLancer() {
        document
            .querySelectorAll('form[action*="/strategies/"][action$="/lancer"] button[disabled]')
            .forEach((b) => { b.disabled = false; });
    }

    // spinner pendant le run ; coche verte a la reussite ; sur echec, ni l'un ni
    // l'autre (la barre rouge + le libelle "Échec" portent l'information).
    basculerIcones(fini, echec = false) {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', fini);
        }
        if (this.hasCheckTarget) {
            this.checkTarget.classList.toggle('hidden', !fini || echec);
        }
    }

    afficher() {
        if (this.hasCardTarget) {
            this.cardTarget.classList.remove('hidden');
        }
    }

    masquer() {
        clearTimeout(this.hideTimer);
        if (this.hasCardTarget) {
            this.cardTarget.classList.add('hidden');
        }
    }

    fermer() {
        this.masquer();
    }
}
