import { Controller } from '@hotwired/stimulus'

/*
 * Geste "Imprimer et marquer poste" de la pile de courriers (clients sans email).
 *
 * Par ligne : POST "marque poste" (idempotent, CSRF) D'ABORD, puis telechargement
 * de la lettre PDF, puis la ligne sort de la liste et un toast "Annuler" (7 s)
 * permet de remettre le courrier a envoyer. La lettre par client ne depend pas du
 * statut, l'ordre POST -> download est donc sur.
 *
 * Lot : on telecharge d'abord le PDF du lot (genere a partir des courriers ENCORE
 * a envoyer) en attendant le blob, PUIS on marque tout poste -> aucun risque de
 * lot vide. Le toast "Annuler" remet l'ensemble a envoyer.
 *
 * Sans JS, le template conserve les formulaires POST natifs : le flux reste
 * fonctionnel (degrade en deux gestes).
 */
export default class extends Controller {
    static targets = ['row', 'count', 'montant', 'med', 'foot', 'summary', 'empty', 'lotBar', 'lotBtn', 'toasts', 'search']
    static values = {
        lotPdfUrl: String,
        marquerLotUrl: String,
        remettreLotUrl: String,
        lotCsrf: String,
        remettreLotCsrf: String,
    }

    connect() {
        this.focusIdx = -1
        this.refresh()
    }

    // --- Geste par ligne -----------------------------------------------------

    async poster(event) {
        event.preventDefault()
        const btn = event.currentTarget
        const row = btn.closest('[data-courrier-envoi-target="row"]')
        btn.disabled = true
        try {
            const data = await this.postForm(btn.dataset.envoyeUrl, { _token: btn.dataset.envoyeToken })
            if (!data.ok) throw new Error(data.erreur || 'Marquage refusé')

            this.telechargerLien(btn.dataset.lettreUrl)
            this.leave(row)
            this.toast('Courrier posté', btn.dataset.nom, () =>
                this.postForm(btn.dataset.remettreUrl, { _token: btn.dataset.remettreToken })
                    .then(() => this.restore(row)),
            )
        } catch (e) {
            btn.disabled = false
            this.toast('Échec du marquage', this.message(e), null, true)
        }
    }

    // --- Geste de lot --------------------------------------------------------

    async posterLot(event) {
        if (event) event.preventDefault()
        const rows = this.activeRows()
        if (!rows.length) return
        this.lotBtnTarget.disabled = true
        try {
            // 1. Telecharger le lot (ZIP : un PDF releve+factures par client) AVANT
            // de marquer (sinon le lot serait vide).
            await this.telechargerBlob(this.lotPdfUrlValue, 'courriers-recouvrement.zip')
            // 2. Marquer tout poste.
            const data = await this.postForm(this.marquerLotUrlValue, { _token: this.lotCsrfValue })
            if (!data.ok) throw new Error(data.erreur || 'Marquage refusé')

            const ids = data.ids || []
            rows.forEach((r) => this.leave(r))
            this.toast(`${data.nb} courriers postés`, 'Lot imprimé', () =>
                this.postFormIds(this.remettreLotUrlValue, this.remettreLotCsrfValue, ids)
                    .then(() => rows.forEach((r) => this.restore(r))),
            )
        } catch (e) {
            this.toast("Échec de l'impression du lot", this.message(e), null, true)
        } finally {
            this.lotBtnTarget.disabled = false
        }
    }

    // --- Sortie / retour de ligne -------------------------------------------

    leave(row) {
        row.dataset.posted = '1'
        this.clearFocus()
        this.focusIdx = -1
        row.classList.add('opacity-0')
        row.style.transition = 'opacity .25s ease'
        window.setTimeout(() => { row.hidden = true; this.refresh() }, 250)
    }

    restore(row) {
        delete row.dataset.posted
        row.classList.remove('opacity-0')
        row.hidden = false
        this.filtrer()
        this.refresh()
    }

    // Lignes "a poster" (non encore postees), pour les compteurs et le lot.
    activeRows() {
        return this.rowTargets.filter((r) => r.dataset.posted !== '1')
    }

    // Lignes visibles a l'ecran (non postees ET non masquees par la recherche).
    visibleRows() {
        return this.rowTargets.filter((r) => r.dataset.posted !== '1' && !r.hidden)
    }

    // --- Recherche client (filtrage instantane cote client) ------------------

    filtrer() {
        const q = (this.hasSearchTarget ? this.searchTarget.value : '').trim().toLowerCase()
        this.rowTargets.forEach((r) => {
            if (r.dataset.posted === '1') return
            r.hidden = '' !== q && !(r.dataset.nom || '').toLowerCase().includes(q)
        })
        this.clearFocus()
        this.focusIdx = -1
    }

    // --- Navigation clavier (fleches / j-k, Entree = poster, A = apercu) ------

    clavier(event) {
        const actif = document.activeElement
        if (actif && ['INPUT', 'TEXTAREA', 'SELECT'].includes(actif.tagName)) {
            if ('Escape' === event.key) { actif.blur() }
            return
        }
        const rows = this.visibleRows()
        if (!rows.length) return

        if ('ArrowDown' === event.key || 'j' === event.key) {
            event.preventDefault()
            this.focusIdx = Math.min(rows.length - 1, this.focusIdx + 1)
            this.appliquerFocus(rows)
        } else if ('ArrowUp' === event.key || 'k' === event.key) {
            event.preventDefault()
            this.focusIdx = Math.max(0, this.focusIdx - 1)
            this.appliquerFocus(rows)
        } else if ('Enter' === event.key && rows[this.focusIdx]) {
            event.preventDefault()
            const btn = rows[this.focusIdx].querySelector('button[data-action~="courrier-envoi#poster"]')
            if (btn) btn.click()
        } else if (('a' === event.key || 'A' === event.key) && rows[this.focusIdx]) {
            event.preventDefault()
            const lien = rows[this.focusIdx].querySelector('a[href]')
            if (lien) window.open(lien.href, '_blank')
        }
    }

    appliquerFocus(rows) {
        this.clearFocus()
        const r = rows[this.focusIdx]
        if (r) { r.classList.add('fc-row-focus'); r.scrollIntoView({ block: 'nearest' }) }
    }

    clearFocus() {
        this.rowTargets.forEach((r) => r.classList.remove('fc-row-focus'))
    }

    // --- Compteurs (synthese, badges, pied) ---------------------------------

    refresh() {
        const rows = this.activeRows()
        const n = rows.length
        let total = 0
        let med = 0
        rows.forEach((r) => {
            total += parseFloat(r.dataset.montant || '0')
            med += parseInt(r.dataset.med || '0', 10)
        })

        this.countTargets.forEach((el) => { el.textContent = String(n) })
        if (this.hasMontantTarget) this.montantTarget.innerHTML = this.euros(total) + ' <small>€</small>'
        if (this.hasMedTarget) this.medTarget.textContent = String(med)
        if (this.hasFootTarget) this.footTarget.textContent = n > 1 ? `${n} courriers en attente` : `${n} courrier en attente`

        const vide = 0 === n
        if (this.hasSummaryTarget) this.summaryTarget.hidden = vide
        if (this.hasLotBarTarget) this.lotBarTarget.hidden = vide
        if (this.hasEmptyTarget) this.emptyTarget.hidden = !vide
    }

    euros(n) {
        return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/ |\s/g, ' ')
    }

    // --- Telechargement ------------------------------------------------------

    telechargerLien(url) {
        const a = document.createElement('a')
        a.href = url
        a.download = ''
        document.body.appendChild(a)
        a.click()
        a.remove()
    }

    async telechargerBlob(url, nom) {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        if (!res.ok) throw new Error('Téléchargement impossible (' + res.status + ')')
        const blob = await res.blob()
        const objectUrl = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = objectUrl
        a.download = nom
        document.body.appendChild(a)
        a.click()
        a.remove()
        URL.revokeObjectURL(objectUrl)
    }

    // --- Requetes ------------------------------------------------------------

    async postForm(url, fields) {
        const body = new FormData()
        Object.entries(fields).forEach(([k, v]) => body.append(k, v))

        return this.envoyer(url, body)
    }

    postFormIds(url, token, ids) {
        const body = new FormData()
        body.append('_token', token)
        ids.forEach((id) => body.append('ids[]', id))

        return this.envoyer(url, body)
    }

    async envoyer(url, body) {
        const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        if (!res.ok) {
            let msg = 'Erreur ' + res.status
            try { const j = await res.json(); msg = j.erreur || msg } catch (e) { /* corps non JSON */ }
            throw new Error(msg)
        }

        return res.json()
    }

    message(e) {
        return e && e.message ? e.message : 'Une erreur est survenue.'
    }

    // --- Toast ---------------------------------------------------------------

    toast(titre, sous, onUndo = null, erreur = false) {
        const el = document.createElement('div')
        el.className = 'flex items-center gap-3 rounded-md px-4 py-3 text-sm text-white shadow-2xl shadow-navy/40 '
            + (erreur ? 'bg-negative' : 'bg-navy-deep')
        const coche = '<svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-gold-soft" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'
        const alerte = '<svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>'
        el.innerHTML = (erreur ? alerte : coche)
            + '<div class="flex-1"><div class="font-medium"></div><div class="text-xs text-white/60"></div></div>'
        el.querySelector('.font-medium').textContent = titre
        el.querySelector('.text-white\\/60').textContent = sous || ''

        const timer = window.setTimeout(() => el.remove(), 7000)
        if (onUndo) {
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.className = 'rounded border border-white/30 px-3 py-1.5 text-xs font-medium transition hover:bg-white/10'
            btn.textContent = 'Annuler'
            btn.addEventListener('click', () => { window.clearTimeout(timer); el.remove(); onUndo() })
            el.appendChild(btn)
        }
        this.toastsTarget.appendChild(el)
    }
}
