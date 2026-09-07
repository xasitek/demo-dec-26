import { Controller } from '@hotwired/stimulus';

/*
 * Boite a idees.
 *
 * Le panneau est ancre en bas a gauche, rendu en pied de layout ; son
 * declencheur est la derniere ligne de la barre laterale, hors du scope de ce
 * controleur (la barre est en overflow:hidden, un panneau de 22rem y serait
 * coupe). Le lien se fait par delegation sur [data-suggestion-declencheur],
 * ce qui evite un second controleur cote barre laterale.
 *
 * Le panneau est charge au premier clic seulement : le declencheur est rendu
 * sur chaque page, il ne doit rien couter tant qu'on ne l'ouvre pas.
 * Apres un envoi, le contenu est vide pour que la prochaine ouverture reflete
 * l'etat a jour de « mes remontees ».
 */
export default class extends Controller {
    static targets = ['panneau', 'onglet', 'ongletBouton', 'message', 'compteur', 'erreur', 'envoi'];
    static values = { url: String, route: String, path: String };

    connect() {
        this.ouvert = false;

        this.onKey = (event) => {
            if ('Escape' === event.key && this.ouvert) {
                this.fermer();
            }
        };
        /*
         * Un seul listener pour deux roles : ouvrir depuis un declencheur
         * exterieur au controleur, et fermer sur un clic ailleurs. Le test du
         * declencheur passe EN PREMIER — sinon le clic qui vient d'ouvrir le
         * panneau serait aussitot vu comme un clic exterieur, et le refermerait.
         */
        this.onClicDocument = (event) => {
            if (event.target.closest('[data-suggestion-declencheur]')) {
                this.basculer();

                return;
            }

            if (this.ouvert && !this.element.contains(event.target)) {
                this.fermer();
            }
        };

        document.addEventListener('keydown', this.onKey);
        document.addEventListener('click', this.onClicDocument);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
        document.removeEventListener('click', this.onClicDocument);
    }

    async basculer() {
        if (this.ouvert) {
            this.fermer();
            return;
        }

        if (!this.panneauTarget.innerHTML.trim()) {
            await this.charger();
        }

        this.panneauTarget.hidden = false;
        this.ouvert = true;
        this.refleterEtat();

        if (this.hasMessageTarget) {
            this.messageTarget.focus();
        }
    }

    fermer() {
        this.panneauTarget.hidden = true;
        this.ouvert = false;
        this.refleterEtat();
    }

    /*
     * Etat porte par les declencheurs : aria-expanded pour les lecteurs
     * d'ecran, et selecteur qui coupe l'animation de l'ampoule tant que le
     * panneau est ouvert (cf. .nav-idee dans app.css).
     */
    refleterEtat() {
        document.querySelectorAll('[data-suggestion-declencheur]').forEach((declencheur) => {
            declencheur.setAttribute('aria-expanded', this.ouvert ? 'true' : 'false');
        });
    }

    async charger() {
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('route', this.routeValue);
        url.searchParams.set('url', this.pathValue);

        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.ok) {
                this.panneauTarget.innerHTML = await response.text();
            }
        } catch (error) {
            // Echec silencieux : le bouton reste utilisable, un second clic reessaie.
        }
    }

    onglet(event) {
        const choisi = event.currentTarget.dataset.onglet;

        this.ongletTargets.forEach((panneau) => {
            panneau.hidden = panneau.dataset.onglet !== choisi;
        });

        this.ongletBoutonTargets.forEach((bouton) => {
            const actif = bouton.dataset.onglet === choisi;
            bouton.classList.toggle('border-navy', actif);
            bouton.classList.toggle('text-navy', actif);
            bouton.classList.toggle('border-transparent', !actif);
            bouton.classList.toggle('text-ink/50', !actif);
        });
    }

    compter() {
        if (this.hasCompteurTarget && this.hasMessageTarget) {
            const max = this.messageTarget.getAttribute('maxlength');
            this.compteurTarget.textContent = `${this.messageTarget.value.length} / ${max}`;
        }
    }

    async envoyer(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const donnees = new FormData(form);

        this.erreurCachee();
        this.envoiTarget.disabled = true;
        this.envoiTarget.textContent = 'Envoi...';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: donnees,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok && data.ok) {
                this.remercier(data.message);
                return;
            }

            this.erreurAffichee(data.message || "L'envoi a échoué. Réessayez.");
        } catch (error) {
            this.erreurAffichee("L'envoi a échoué. Vérifiez votre connexion.");
        } finally {
            if (this.hasEnvoiTarget) {
                this.envoiTarget.disabled = false;
                this.envoiTarget.textContent = 'Envoyer';
            }
        }
    }

    /*
     * Accuse de reception. Le panneau est vide a la fermeture : la prochaine
     * ouverture rechargera « mes remontees » avec l'envoi qui vient d'etre fait.
     */
    remercier(message) {
        this.panneauTarget.innerHTML = `
            <div class="px-5 py-8 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-positive/10 text-positive">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <p class="mt-3 text-sm text-ink">${this.echapper(message || 'Merci.')}</p>
                <button type="button" data-action="suggestion#fermerEtVider" class="mt-4 rounded-lg border border-hairline px-4 py-1.5 text-xs font-medium text-navy transition hover:bg-surface">Fermer</button>
            </div>`;
    }

    fermerEtVider() {
        this.fermer();
        this.panneauTarget.innerHTML = '';
    }

    erreurAffichee(message) {
        if (this.hasErreurTarget) {
            this.erreurTarget.textContent = message;
            this.erreurTarget.hidden = false;
        }
    }

    erreurCachee() {
        if (this.hasErreurTarget) {
            this.erreurTarget.hidden = true;
        }
    }

    echapper(texte) {
        const noeud = document.createElement('span');
        noeud.textContent = texte;

        return noeud.innerHTML;
    }
}
