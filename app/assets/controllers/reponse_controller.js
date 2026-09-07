import { Controller } from '@hotwired/stimulus'

/*
 * Formulaire de reponse / cloture d'un retour, dans le panneau detail.
 *
 * - Anti-doublon : desactive le bouton d'envoi pendant la soumission (un
 *   double-clic n'envoie pas deux fois). Reactive si l'envoi echoue.
 * - Envoi reussi : la conversation est close -> on ferme le panneau detail ET on
 *   retire la ligne de la liste "a traiter", via un evenement document ecoute par
 *   les controleurs detail-panel (fermeture) et retours (retrait de la ligne).
 *
 * S'appuie sur les evenements Turbo du formulaire :
 *   - turbo:submit-start -> debut()  (le formulaire cible sa turbo-frame detail)
 *   - turbo:submit-end   -> fin()    (event.detail.success = 2xx cote serveur)
 * C'est pourquoi le serveur renvoie 422 (et non 200) sur erreur de validation :
 * sans ca, Turbo croirait a un succes et le panneau se fermerait a tort.
 */
export default class extends Controller {
    static targets = ['bouton']
    static values = { cle: String }

    debut() {
        this.boutonTargets.forEach((b) => {
            b.dataset.htmlInitial = b.innerHTML
            b.disabled = true
            const label = b.dataset.labelChargement || 'Envoi…'
            // Spinner en animation SVG native (SMIL) : aucune classe CSS a purger,
            // aucun rebuild Tailwind necessaire. Tourne autour du centre (12,12).
            b.innerHTML = `<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"><animateTransform attributeName="transform" attributeType="XML" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite"/></path></svg><span>${label}</span>`
        })
    }

    fin(event) {
        const succes = event.detail && event.detail.success
        if (!succes) {
            // Echec (message vide, PJ invalide, envoi KO) : on rend la main et on
            // restaure le libelle initial du bouton pour permettre un nouvel essai.
            this.boutonTargets.forEach((b) => {
                b.disabled = false
                if (undefined !== b.dataset.htmlInitial) {
                    b.innerHTML = b.dataset.htmlInitial
                    delete b.dataset.htmlInitial
                }
            })
            return
        }
        // Succes : le panneau va se fermer et la ligne disparaitre ; le spinner
        // reste affiche jusqu'a la fermeture (pas de restauration necessaire).
        document.dispatchEvent(new CustomEvent('recouvrement:reponse-envoyee', {
            detail: { cle: this.cleValue },
        }))
    }
}
