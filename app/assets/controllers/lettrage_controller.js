import { Controller } from '@hotwired/stimulus'

/*
 * Onglet « Lettrage » : commentaire de la comptable sur une ligne a lettrer.
 *
 * Une seule modale pour toute la table. A l'enregistrement, le serveur renvoie le
 * fragment de la cellule a jour, qu'on remplace en place : pas de rechargement, la
 * position de scroll et les filtres sont conserves.
 */
export default class extends Controller {
    static targets = ['modale', 'texte', 'info', 'erreur', 'valider', 'cellule', 'panneau', 'panneauContenu', 'backdrop']
    static values = { url: String, rapprochementUrl: String, csrf: String, max: Number }

    connect() {
        this.cle = null
        this.onKey = (event) => {
            if ('Escape' !== event.key) return
            // Echap ferme d'abord la modale (au-dessus), sinon le panneau.
            if (!this.modaleTarget.hidden) this.fermer()
            else this.fermerPanneau()
        }
        document.addEventListener('keydown', this.onKey)
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey)
    }

    editer(event) {
        const cle = String(event.params.cle || '')
        if (!cle) return

        const cellule = this.celluleFor(cle)
        const existant = cellule ? cellule.querySelector('span') : null
        const ligne = event.currentTarget.closest('tr')

        this.cle = cle
        this.texteTarget.value = existant ? existant.textContent.trim() : ''
        this.infoTarget.textContent = this.resume(ligne)
        this.cacherErreur()
        this.modaleTarget.hidden = false
        this.texteTarget.focus()
    }

    fermer() {
        this.modaleTarget.hidden = true
        this.cle = null
    }

    async enregistrer() {
        if (!this.cle) return

        const texte = this.texteTarget.value.trim()
        if (this.hasMaxValue && this.maxValue > 0 && texte.length > this.maxValue) {
            this.afficherErreur(`Commentaire trop long (${this.maxValue} caractères maximum).`)
            return
        }

        const corps = new FormData()
        corps.append('_token', this.csrfValue)
        corps.append('commentaire', texte)

        this.validerTarget.disabled = true
        try {
            const reponse = await fetch(this.urlValue.replace('__CLE__', this.cle), {
                method: 'POST',
                body: corps,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            if (!reponse.ok) {
                const json = await reponse.json().catch(() => ({}))
                this.afficherErreur(json.erreur || 'Enregistrement impossible.')
                return
            }
            const cellule = this.celluleFor(this.cle)
            if (cellule) cellule.outerHTML = await reponse.text()
            this.fermer()
        } catch (e) {
            this.afficherErreur('Erreur réseau : le commentaire n\'a pas été enregistré.')
        } finally {
            this.validerTarget.disabled = false
        }
    }

    // ---- Panneau de rapprochement ----

    // Clic sur une ligne : charge le dossier paye le plus probable pour cette ecriture.
    async rapprocher(event) {
        const cle = String(event.params.cle || '')
        if (!cle || !this.hasRapprochementUrlValue) return

        this.ligneCourante(event.currentTarget)
        this.ouvrirPanneau()
        this.panneauContenuTarget.innerHTML = '<p class="px-5 py-6 text-sm text-ink/55">Recherche du dossier correspondant…</p>'

        try {
            const reponse = await fetch(this.rapprochementUrlValue.replace('__CLE__', cle), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            // Sans ce garde-fou, une reponse d'erreur injecterait la page 500 entiere
            // (avec son <style>, qui repeint tout le document) dans le panneau.
            if (!reponse.ok) {
                this.panneauContenuTarget.innerHTML = '<p class="px-5 py-6 text-sm text-negative">Rapprochement indisponible.</p>'
                return
            }
            this.panneauContenuTarget.innerHTML = await reponse.text()
        } catch (e) {
            this.panneauContenuTarget.innerHTML = '<p class="px-5 py-6 text-sm text-negative">Erreur réseau.</p>'
        }
    }

    ouvrirPanneau() {
        this.panneauTarget.classList.remove('translate-x-full')
        this.backdropTarget.hidden = false
    }

    fermerPanneau() {
        this.panneauTarget.classList.add('translate-x-full')
        this.backdropTarget.hidden = true
        this.ligneCourante(null)
    }

    // Surligne la ligne dont le panneau montre le rapprochement.
    ligneCourante(ligne) {
        this.element.querySelectorAll('tr[data-lettrage-cle-param]')
            .forEach((tr) => tr.classList.remove('bg-navy/[0.06]'))
        if (ligne) ligne.classList.add('bg-navy/[0.06]')
    }

    // Empeche un clic dans la cellule commentaire d'ouvrir aussi le panneau.
    stopper(event) {
        event.stopPropagation()
    }

    // La cellule peut avoir ete inserree par le scroll infini : on la cherche dans le
    // DOM plutot que dans les cibles connues au connect().
    celluleFor(cle) {
        return this.element.querySelector(`[data-lettrage-target="cellule"][data-cle="${cle}"]`)
    }

    // Rappel de la ligne concernee dans la modale (client, montant, libelle).
    resume(ligne) {
        if (!ligne) return ''
        const cellules = ligne.querySelectorAll('td')
        const texte = (index) => (cellules[index] ? cellules[index].textContent.replace(/\s+/g, ' ').trim() : '')

        return [texte(4), texte(6), texte(5)].filter(Boolean).join(' — ')
    }

    afficherErreur(message) {
        this.erreurTarget.textContent = message
        this.erreurTarget.hidden = false
    }

    cacherErreur() {
        this.erreurTarget.hidden = true
        this.erreurTarget.textContent = ''
    }
}
