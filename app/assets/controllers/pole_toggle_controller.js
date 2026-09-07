import { Controller } from '@hotwired/stimulus'

/*
 * Affiche le menu "Pole" uniquement quand le role Comptable est coche.
 * Cibles : comptable (la case ROLE_COMPTABLE), pole (le bloc du menu deroulant).
 * A brancher : data-controller="pole-toggle" + data-action="change->pole-toggle#refresh"
 * sur le conteneur du formulaire.
 */
export default class extends Controller {
    static targets = ['comptable', 'pole']

    connect() {
        this.refresh()
    }

    refresh() {
        const actif = this.hasComptableTarget && this.comptableTarget.checked
        this.poleTargets.forEach((p) => { p.hidden = !actif })
    }
}
