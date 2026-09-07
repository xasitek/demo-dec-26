import { Controller } from '@hotwired/stimulus'

/*
 * Page "Factures sans PDF" : televersement du PDF manquant d'une facture, avec
 * retrait de la ligne a l'upload et temps reel.
 *
 * Temps reel : ecoute "recouvrement:facture-sans-pdf" (redispatch par le controleur
 * partage "realtime", une seule connexion Mercure par onglet). action=remove ->
 * retire la ligne (upload fait ailleurs) ; action=refresh -> recharge la liste
 * (nouvelles factures apres un rafraichissement ETL).
 */
export default class extends Controller {
    static targets = ['items', 'tableau', 'vide', 'compteur']
    static values = { uploadUrl: String, fragmentUrl: String, csrf: String }

    // Le formulaire ne se soumet jamais nativement : l'upload part au choix du fichier.
    empecherSubmit(event) {
        event.preventDefault()
    }

    // Upload declenche a la selection d'un fichier.
    async uploader(event) {
        const input = event.target
        const row = input.closest('tr[data-ecriture]')
        if (!row || !input.files || !input.files[0]) return

        const ecriture = row.getAttribute('data-ecriture')
        const etat = row.querySelector('[data-role="etat"]')
        const body = new FormData()
        body.append('_token', this.csrfValue)
        body.append('ecriture_id', ecriture)
        body.append('fichier', input.files[0])

        if (etat) { etat.textContent = 'Envoi…'; etat.className = 'ml-2 text-xs text-ink/60' }

        try {
            const res = await fetch(this.uploadUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            })
            const data = await res.json().catch(() => ({}))
            if (res.ok && data.ok) {
                this.retirerLigne(ecriture)
            } else {
                if (etat) { etat.textContent = data.erreur || 'Échec de l\'envoi'; etat.className = 'ml-2 text-xs font-medium text-red-600' }
                input.value = ''
            }
        } catch (e) {
            if (etat) { etat.textContent = 'Erreur réseau'; etat.className = 'ml-2 text-xs font-medium text-red-600' }
            input.value = ''
        }
    }

    // --- Temps reel ----------------------------------------------------------

    onRealtime(event) {
        const detail = event.detail || {}
        if ('remove' === detail.action && detail.ecriture_id) {
            this.retirerLigne(detail.ecriture_id)
        } else if ('refresh' === detail.action) {
            this.recharger()
        }
    }

    async recharger() {
        try {
            const res = await fetch(this.fragmentUrlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            if (!res.ok || !this.hasItemsTarget) return
            this.itemsTarget.innerHTML = await res.text()
            this.majCompteur()
        } catch (e) {
            // silencieux : le prochain chargement montrera la liste a jour
        }
    }

    // --- Utilitaires ---------------------------------------------------------

    retirerLigne(ecriture) {
        if (!this.hasItemsTarget) return
        const row = this.itemsTarget.querySelector(`tr[data-ecriture="${CSS.escape(ecriture)}"]`)
        if (!row) return
        row.style.transition = 'opacity .2s ease'
        row.style.opacity = '0'
        window.setTimeout(() => { row.remove(); this.majCompteur() }, 200)
    }

    majCompteur() {
        const n = this.hasItemsTarget ? this.itemsTarget.querySelectorAll('tr[data-ecriture]').length : 0
        if (this.hasCompteurTarget) this.compteurTarget.textContent = n
        const vide = n <= 0
        if (this.hasTableauTarget) this.tableauTarget.style.display = vide ? 'none' : ''
        if (this.hasVideTarget) this.videTarget.style.display = vide ? '' : 'none'
    }
}
