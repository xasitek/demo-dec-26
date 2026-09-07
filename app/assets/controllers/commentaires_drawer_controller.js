import { Controller } from '@hotwired/stimulus'

/*
 * Panneau lateral des commentaires d'audit (liste Garanties) : charge en AJAX les
 * commentaires de la ligne cliquee, sans quitter la liste ni son defilement.
 *
 * Chargement en fetch plutot qu'en lien vers une turbo-frame : l'icone doit couper la
 * propagation du clic (`:stop`), sinon elle ouvre AUSSI le panneau de detail de la
 * ligne — et Turbo intercepte les liens de frame en phase de bouillonnement, donc
 * `:stop` lui ferait rater le clic et declencherait une navigation pleine page. Le
 * bouton evite tout ce couplage. Meme approche que apercu_drawer_controller.
 *
 * L'URL complete est portee par le bouton (data-url) : l'ancrage d'un commentaire est
 * soit une DG, soit un couple cle + oidech, donc pas un simple identifiant a substituer.
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'contenu']

    connect() {
        this.onKey = (e) => { if ('Escape' === e.key) this.fermer() }
        document.addEventListener('keydown', this.onKey)
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey)
    }

    async ouvrir(event) {
        const url = event.currentTarget.dataset.url
        if (!url) return

        this.contenuTarget.innerHTML = '<p class="px-5 py-5 text-sm text-ink/50">Chargement...</p>'
        this.montrer()

        try {
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            this.contenuTarget.innerHTML = res.ok
                ? await res.text()
                : '<p class="px-5 py-5 text-sm text-negative">Commentaires indisponibles.</p>'
        } catch (e) {
            this.contenuTarget.innerHTML = '<p class="px-5 py-5 text-sm text-negative">Commentaires indisponibles.</p>'
        }

        // Le formulaire d'ajout vit dans une turbo-frame : le focus sur la zone de
        // saisie evite un aller-retour a la souris quand on vient pour ecrire.
        this.contenuTarget.querySelector('textarea')?.focus()
    }

    montrer() {
        this.panelTarget.classList.remove('translate-x-full')
        this.backdropTarget.classList.remove('hidden')
    }

    fermer() {
        this.panelTarget.classList.add('translate-x-full')
        this.backdropTarget.classList.add('hidden')
    }
}
