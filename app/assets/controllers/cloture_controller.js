import { Controller } from '@hotwired/stimulus'

/*
 * Modale de clôture d'un retour "sans répondre", ouverte par l'icône rouge du
 * header du panneau détail (le détail-panel émet "recouvrement:ouvrir-cloture"
 * avec l'id du retour + la clé de conversation).
 *
 * Confirmer -> POST /retours/{id}/traiter (jeton CSRF fixe) -> à la réussite, on
 * réutilise l'événement "recouvrement:reponse-envoyee" (déjà écouté) pour fermer
 * le panneau et retirer la ligne. Le retour reste hors de la liste "à traiter".
 */
export default class extends Controller {
    static targets = ['backdrop', 'dialog', 'motif', 'confirmer']
    static values = { url: String, csrf: String }

    ouvrir(event) {
        this.retourId = event.detail && event.detail.id
        this.cle = (event.detail && event.detail.cle) || ''
        if (!this.retourId) return

        this.motifTarget.value = ''
        this.confirmerTarget.disabled = false
        this.backdropTarget.classList.remove('hidden')
        this.dialogTarget.classList.remove('hidden')
        this.motifTarget.focus()
    }

    annuler() {
        this.fermer()
    }

    keydown(event) {
        if ('Escape' === event.key && !this.dialogTarget.classList.contains('hidden')) {
            this.fermer()
        }
    }

    async confirmer() {
        if (!this.retourId) return
        this.confirmerTarget.disabled = true

        try {
            const body = new FormData()
            body.append('_token', this.csrfValue)
            body.append('commentaire', this.motifTarget.value)
            const res = await fetch(this.urlValue.replace('__ID__', String(this.retourId)), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            })
            if (!res.ok) throw new Error('Clôture refusée')
            this.fermer()
            // Ferme le panneau détail + retire la ligne (mêmes écouteurs que la réponse).
            document.dispatchEvent(new CustomEvent('recouvrement:reponse-envoyee', {
                detail: { cle: this.cle },
            }))
        } catch (e) {
            this.confirmerTarget.disabled = false
            window.alert('Échec de la clôture.')
        }
    }

    fermer() {
        this.backdropTarget.classList.add('hidden')
        this.dialogTarget.classList.add('hidden')
    }
}
