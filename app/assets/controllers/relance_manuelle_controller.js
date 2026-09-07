import { Controller } from '@hotwired/stimulus'

/*
 * Vue de relance MANUELLE : la comptable relance un client entier (toutes ses
 * factures) ou une facture precise. Deux modes : "Relancer" (niveau calcule auto
 * selon la regle du compte) et "Mise en demeure" (force le niveau MED). Envoi
 * individuel (bouton par ligne), par facture (depliage) ou groupe (cases a cocher).
 * Tout passe par le meme endpoint POST (reutilise le pipeline).
 */
export default class extends Controller {
    static targets = ['row', 'selectAll', 'count', 'countMed', 'selectionBtn', 'selectionMedBtn', 'toasts', 'clientRow']
    static values = { envoyerUrl: String, facturesUrl: String, csrf: String }

    connect() {
        this.refreshCount()
    }

    // --- Selection -----------------------------------------------------------

    toggleAll(event) {
        this.rowTargets.forEach((cb) => { cb.checked = event.target.checked })
        this.refreshCount()
    }

    toggleOne() {
        this.refreshCount()
    }

    checkedComptes() {
        return this.rowTargets.filter((cb) => cb.checked).map((cb) => cb.value)
    }

    refreshCount() {
        const n = this.checkedComptes().length
        if (this.hasCountTarget) this.countTarget.textContent = String(n)
        if (this.hasCountMedTarget) this.countMedTarget.textContent = String(n)
        if (this.hasSelectionBtnTarget) this.selectionBtnTarget.disabled = 0 === n
        if (this.hasSelectionMedBtnTarget) this.selectionMedBtnTarget.disabled = 0 === n
        if (this.hasSelectAllTarget) {
            const total = this.rowTargets.length
            this.selectAllTarget.checked = total > 0 && n === total
        }
    }

    // --- Envois --------------------------------------------------------------

    relancerClient(event) {
        const btn = event.currentTarget
        this.envoyer({ comptes: [btn.dataset.compte] }, btn, '1 client', btn.dataset.niveau)
    }

    relancerFacture(event) {
        const btn = event.currentTarget
        this.envoyer({ factures: [btn.dataset.ecriture] }, btn, '1 facture', btn.dataset.niveau)
    }

    relancerSelection(event) {
        const comptes = this.checkedComptes()
        if (!comptes.length) return
        this.envoyer({ comptes }, event.currentTarget, `${comptes.length} client(s)`, event.currentTarget.dataset.niveau)
    }

    async envoyer(payload, btn, quoi, niveau) {
        if (btn) btn.disabled = true
        try {
            const body = new FormData()
            body.append('_token', this.csrfValue)
            body.append('niveau', ['1', '2', 'med'].includes(niveau) ? niveau : '1')
            ;(payload.comptes || []).forEach((c) => body.append('comptes[]', c))
            ;(payload.factures || []).forEach((f) => body.append('factures[]', f))

            const res = await fetch(this.envoyerUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            })
            if (!res.ok) throw new Error('Envoi refusé (' + res.status + ')')
            const data = await res.json()
            this.toastBilan(data, quoi)
        } catch (e) {
            this.toast('Échec de la relance', this.message(e), true)
        } finally {
            if (btn) btn.disabled = false
        }
    }

    toastBilan(data, quoi) {
        const parts = []
        if (data.envoyes) parts.push(`${data.envoyes} envoyée(s)`)
        if (data.courriers) parts.push(`${data.courriers} courrier(s)`)
        if (data.ignores) parts.push(`${data.ignores} ignorée(s)`)
        const erreur = 0 === (data.envoyes || 0) + (data.courriers || 0)
        this.toast(
            erreur ? `Relance ${quoi} : rien envoyé` : `Relance ${quoi} envoyée`,
            parts.length ? parts.join(' · ') : 'Aucune action',
            erreur,
        )
    }

    // --- Depliage : factures d'un client ------------------------------------

    async deplier(event) {
        const btn = event.currentTarget
        const compte = btn.dataset.compte
        const row = btn.closest('tr')
        const suivant = row.nextElementSibling

        // Deja deplie -> on replie.
        if (suivant && suivant.dataset.detailsFor === compte) {
            suivant.remove()
            btn.classList.remove('text-navy')
            return
        }

        try {
            const res = await fetch(this.facturesUrlValue.replace('__COMPTE__', encodeURIComponent(compte)), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            if (!res.ok) return
            const html = await res.text()
            const tr = document.createElement('tr')
            tr.dataset.detailsFor = compte
            tr.innerHTML = `<td colspan="9" class="p-0">${html}</td>`
            row.after(tr)
            btn.classList.add('text-navy')
        } catch (e) {
            // silencieux
        }
    }

    // --- Toast ---------------------------------------------------------------

    toast(titre, sous, erreur = false) {
        if (!this.hasToastsTarget) return
        const el = document.createElement('div')
        el.className = 'flex items-start gap-3 rounded-md px-4 py-3 text-sm text-white shadow-2xl shadow-navy/40 '
            + (erreur ? 'bg-negative' : 'bg-navy-deep')
        el.innerHTML = '<div class="flex-1"><div class="font-medium"></div><div class="text-xs text-white/60"></div></div>'
        el.querySelector('.font-medium').textContent = titre
        el.querySelector('.text-white\\/60').textContent = sous || ''
        this.toastsTarget.appendChild(el)
        window.setTimeout(() => el.remove(), 6000)
    }

    message(e) {
        return e && e.message ? e.message : 'Une erreur est survenue.'
    }
}
