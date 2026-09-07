import { Controller } from '@hotwired/stimulus'

/*
 * Drawer d'apercu client (vue Clients) : ouvre un panneau lateral et y charge en AJAX
 * l'apercu condense d'un compte (identite, encours, derniers echanges), sans quitter la
 * recherche. Ferme au clic sur le fond, sur la croix, ou avec Echap.
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'contenu']
    static values = { url: String }

    connect() {
        this.onKey = (e) => { if ('Escape' === e.key) this.fermer() }
        document.addEventListener('keydown', this.onKey)
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey)
    }

    async ouvrir(event) {
        const compte = event.currentTarget.dataset.compte
        if (!compte) return
        this.contenuTarget.innerHTML = '<div class="p-6 text-center text-sm text-ink/50">Chargement...</div>'
        this.montrer()
        try {
            const res = await fetch(this.urlValue.replace('__COMPTE__', encodeURIComponent(compte)), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            this.contenuTarget.innerHTML = res.ok
                ? await res.text()
                : '<div class="p-6 text-sm text-negative">Aperçu indisponible.</div>'
        } catch (e) {
            this.contenuTarget.innerHTML = '<div class="p-6 text-sm text-negative">Aperçu indisponible.</div>'
        }
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
