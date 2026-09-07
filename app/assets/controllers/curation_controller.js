import { Controller } from '@hotwired/stimulus'

/*
 * Curation d'un compte : dans la vue Clients et la fiche, chaque ligne porte un
 * bouton STATUT (croix rouge = ecarte / coche verte = relancable). Clic = bascule
 * en AJAX (pas de rechargement). Une decision est toujours MANUELLE : elle prime
 * sur les regles de relance.
 */
const SVG_X = '<svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>'
const SVG_CHECK = '<svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'
const CLS_BASE = 'curation-statut inline-flex items-center justify-center rounded-md border p-1.5 transition '
const CLS_X = 'border-negative/40 text-negative hover:bg-negative/10'
const CLS_CHECK = 'border-positive/40 text-positive hover:bg-positive/10'

export default class extends Controller {
    static targets = ['toasts']
    static values = {
        deciderUrl: String,
        csrf: String,
    }

    async basculer(event) {
        const btn = event.currentTarget
        const versEcarte = '1' !== btn.dataset.ecarte // relancable -> on ecarte, et inversement
        btn.disabled = true
        try {
            const body = new FormData()
            body.append('_token', this.csrfValue)
            body.append('compte', btn.dataset.compte)
            body.append('etat', versEcarte ? 'ecarte' : 'relancable')
            const res = await fetch(this.deciderUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            })
            if (!res.ok) throw new Error()
            this.appliquer(btn, versEcarte)
        } catch (e) {
            this.toast('Échec', 'Statut non modifié.', true)
        } finally {
            btn.disabled = false
        }
    }

    appliquer(btn, ecarte) {
        btn.dataset.ecarte = ecarte ? '1' : '0'
        btn.className = CLS_BASE + (ecarte ? CLS_X : CLS_CHECK)
        btn.innerHTML = ecarte ? SVG_X : SVG_CHECK
        btn.title = ecarte ? 'Écarté — cliquer pour relancer' : 'Relançable — cliquer pour écarter'
    }

    toast(titre, sous, erreur) {
        if (!this.hasToastsTarget) return
        const el = document.createElement('div')
        el.className = 'flex items-start gap-3 rounded-md px-4 py-3 text-sm text-white shadow-2xl shadow-navy/40 '
            + (erreur ? 'bg-negative' : 'bg-navy-deep')
        el.innerHTML = '<div class="flex-1"><div class="font-medium"></div><div class="text-xs text-white/60"></div></div>'
        el.querySelector('.font-medium').textContent = titre
        el.querySelector('.text-white\\/60').textContent = sous || ''
        this.toastsTarget.appendChild(el)
        window.setTimeout(() => el.remove(), 5000)
    }
}
