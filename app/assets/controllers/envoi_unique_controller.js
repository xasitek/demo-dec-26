import { Controller } from '@hotwired/stimulus'

/**
 * Anti double-envoi sur un formulaire classique (POST puis navigation).
 *
 * Le drapeau `enCours` est le VRAI garde : il est pose de facon synchrone dans
 * l'evenement `submit`, donc il bloque un second clic meme s'il arrive avant que le
 * navigateur ait redessine les boutons grises. Se reposer sur `disabled` seul
 * laisserait passer un double-clic tres rapide.
 *
 * Le grisage n'est que la traduction visible, et il est repousse d'un tick : un
 * bouton desactive PENDANT l'evenement submit peut voir son `formaction` ou sa
 * valeur ignores selon les navigateurs, et ici le bouton du modal porte justement un
 * `formaction` (refus / demande de correction).
 *
 * Pose sur le FORMULAIRE et non sur un bouton : l'ecran de verification comptable a
 * deux chemins de soumission — « Valider », et « Confirmer » dans le modal — et un
 * garde par bouton n'empecherait pas d'enchainer l'un puis l'autre.
 *
 * Le retour arriere du navigateur restitue la page depuis son cache avec les boutons
 * tels qu'ils etaient, donc grises : `pageshow` les rend a leur etat.
 */
export default class extends Controller {
    connect() {
        this.enCours = false
        this.restaurer = (event) => {
            if (event.persisted) this.reinitialiser()
        }
        window.addEventListener('pageshow', this.restaurer)
    }

    disconnect() {
        window.removeEventListener('pageshow', this.restaurer)
    }

    garder(event) {
        if (this.enCours) {
            event.preventDefault()
            event.stopImmediatePropagation()

            return
        }
        this.enCours = true

        setTimeout(() => {
            this.boutons().forEach((bouton) => {
                bouton.disabled = true
                bouton.setAttribute('aria-busy', 'true')
            })
        }, 0)
    }

    reinitialiser() {
        this.enCours = false
        this.boutons().forEach((bouton) => {
            bouton.disabled = false
            bouton.removeAttribute('aria-busy')
        })
    }

    /** Tous les boutons qui soumettent ce formulaire, modal compris. */
    boutons() {
        return this.element.querySelectorAll('button[type="submit"]')
    }
}
