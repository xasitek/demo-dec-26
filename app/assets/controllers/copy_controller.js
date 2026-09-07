import { Controller } from '@hotwired/stimulus'

/*
 * Copie une valeur dans le presse-papier au clic (ex. code compte / clé écriture
 * à coller dans Progiciel). Feedback bref « Copié ! » sur le libellé.
 * Usage :
 *   <button data-controller="copy" data-copy-text-value="ACAPDS..." data-action="click->copy#copier">
 *     <span data-copy-target="label">ACAPDS...</span>
 *   </button>
 * navigator.clipboard est dispo sur 127.0.0.1 / localhost (contexte sûr) et en https.
 */
export default class extends Controller {
    static targets = ['label']
    static values = { text: String }

    async copier() {
        const texte = this.textValue
        if (!texte) return
        try {
            await navigator.clipboard.writeText(texte)
        } catch (e) {
            // Repli (contexte non sûr) : sélection via un textarea temporaire.
            const zone = document.createElement('textarea')
            zone.value = texte
            zone.style.position = 'fixed'
            zone.style.opacity = '0'
            document.body.appendChild(zone)
            zone.select()
            try { document.execCommand('copy') } catch (err) { /* ignore */ }
            zone.remove()
        }
        this.flash()
    }

    flash() {
        if (!this.hasLabelTarget) return
        if (undefined === this.original) {
            this.original = this.labelTarget.textContent
        }
        this.labelTarget.textContent = 'Copié !'
        this.labelTarget.classList.add('text-positive')
        window.clearTimeout(this.timer)
        this.timer = window.setTimeout(() => {
            this.labelTarget.textContent = this.original
            this.labelTarget.classList.remove('text-positive')
        }, 1200)
    }
}
