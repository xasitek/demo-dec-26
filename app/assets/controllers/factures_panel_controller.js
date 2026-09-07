import { Controller } from '@hotwired/stimulus'

/*
 * Panneau "factures du client" : glisse depuis la droite. Le contenu (liste de
 * cartes) est chargé en AJAX au clic, selon le compte.
 *
 * Configuration du gel de relance :
 *  - RELANCE SITE = un bouton par carte -> ouvre le modal "message au site". Le
 *    message est OBLIGATOIRE ; l'enregistrer met la facture en relance site
 *    automatiquement (POST action=relance_site + note pour cette écriture).
 *  - NE PAS RELANCER / RÉACTIVER = actions GROUPÉES sur les cases cochées (sans
 *    message).
 * Chaque POST renvoie le fragment à jour, qu'on réinjecte (pas de rechargement).
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'content']
    static values = { url: String }

    async open(event) {
        const compte = event.params.compte
        if (!compte) {
            return
        }
        this.show()
        await this.charger(compte)
    }

    async charger(compte) {
        this.contentTarget.innerHTML = '<p class="p-6 text-sm text-ink/50">Chargement...</p>'
        try {
            const reponse = await fetch(this.urlValue.replace('__COMPTE__', encodeURIComponent(compte)), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            // Sans ce garde-fou, une reponse d'erreur injectait la PAGE 500 complete
            // (avec son <style>, qui repeint tout le document) dans le panneau.
            if (!reponse.ok) {
                this.contentTarget.innerHTML = '<p class="p-6 text-sm text-negative">Erreur de chargement des factures.</p>'
                return
            }
            this.contentTarget.innerHTML = await reponse.text()
            this.rechercher()
            this.majSelection()
        } catch (e) {
            this.contentTarget.innerHTML = '<p class="p-6 text-sm text-negative">Erreur de chargement des factures.</p>'
        }
    }

    // Recherche plein-texte (pièce / réf / montant / étab) + filtre statut, instantané.
    rechercher() {
        const q = this.norm(this.valeur('[data-role=recherche]'))
        const statut = this.valeur('[data-role=filtre-statut]')
        let visibles = 0
        this.contentTarget.querySelectorAll('.facture-carte').forEach((carte) => {
            const okTexte = '' === q || this.norm(carte.dataset.search || '').includes(q)
            const okStatut = '' === statut || carte.dataset.statut === statut
            const visible = okTexte && okStatut
            carte.classList.toggle('hidden', !visible)
            if (visible) {
                visibles++
            }
        })
        const compteur = this.contentTarget.querySelector('[data-role=compteur-visibles]')
        if (compteur) {
            compteur.textContent = String(visibles)
        }
    }

    toutCocher(event) {
        const coche = event.currentTarget.checked
        this.contentTarget.querySelectorAll('.facture-carte:not(.hidden) input[name="ecritures[]"]').forEach((c) => {
            c.checked = coche
        })
        this.majSelection()
    }

    // Clic n'importe où sur la carte = coche/décoche (sauf sur un élément interactif :
    // bouton "Relance site", lien PDF, la case elle-même).
    toggleCarte(event) {
        if (event.target.closest('button, a, input, label, details, summary')) {
            return
        }
        const cb = event.currentTarget.querySelector('input[name="ecritures[]"]')
        if (!cb) {
            return
        }
        cb.checked = !cb.checked
        this.majSelection()
    }

    // Met à jour le compteur de sélection, la surbrillance des cartes cochées, et
    // l'état (dés)activé des actions groupées.
    majSelection() {
        let n = 0
        this.contentTarget.querySelectorAll('input[name="ecritures[]"]').forEach((cb) => {
            const carte = cb.closest('.facture-carte')
            if (cb.checked) {
                n++
                if (carte) {
                    carte.style.borderColor = '#2D3250'
                    carte.style.backgroundColor = 'rgba(45,50,80,0.04)'
                }
            } else if (carte) {
                carte.style.borderColor = ''
                carte.style.backgroundColor = ''
            }
        })
        const compteur = this.contentTarget.querySelector('[data-role=compteur-selection]')
        if (compteur) {
            compteur.textContent = String(n)
        }
        this.contentTarget.querySelectorAll('[data-role=action-groupee]').forEach((b) => {
            b.disabled = 0 === n
        })
    }

    // Actions groupées sans message : ne_pas_relancer / degel.
    async configurer(event) {
        const coches = [...this.contentTarget.querySelectorAll('input[name="ecritures[]"]:checked')].map((c) => c.value)
        if (0 === coches.length) {
            return
        }
        const body = new FormData()
        body.append('action', event.params.action)
        coches.forEach((v) => body.append('ecritures[]', v))
        await this.envoyer(body, event.currentTarget)
    }

    // Ouvre le modal "message au site" pour une facture (pré-remplit si déjà configurée).
    ouvrirMessage(event) {
        this.ecritureCourante = event.params.ecriture
        const modal = this.contentTarget.querySelector('[data-role=msg-modal]')
        if (!modal) {
            return
        }
        modal.querySelector('[data-role=msg-titre]').textContent = event.params.piece || this.ecritureCourante
        const input = modal.querySelector('[data-role=msg-input]')
        input.value = event.params.note || ''
        const cadence = modal.querySelector('[data-role=msg-cadence]')
        if (cadence) {
            cadence.value = event.params.cadence || '2s'
        }
        modal.classList.remove('hidden')
        this.validerMessage()
        input.focus()
    }

    fermerMessage() {
        const modal = this.contentTarget.querySelector('[data-role=msg-modal]')
        if (modal) {
            modal.classList.add('hidden')
        }
    }

    // Message obligatoire : "Enregistrer" reste désactivé tant que le champ est vide.
    validerMessage() {
        const modal = this.contentTarget.querySelector('[data-role=msg-modal]')
        if (!modal) {
            return
        }
        const vide = '' === modal.querySelector('[data-role=msg-input]').value.trim()
        modal.querySelector('[data-role=msg-save]').disabled = vide
    }

    // Enregistrer le message = mise en relance site automatique de la facture.
    async enregistrerMessage(event) {
        const modal = this.contentTarget.querySelector('[data-role=msg-modal]')
        if (!modal || !this.ecritureCourante) {
            return
        }
        const message = modal.querySelector('[data-role=msg-input]').value.trim()
        if ('' === message) {
            return
        }
        const body = new FormData()
        body.append('action', 'relance_site')
        body.append('ecritures[]', this.ecritureCourante)
        body.append(`notes[${this.ecritureCourante}]`, message)
        const cadence = modal.querySelector('[data-role=msg-cadence]')
        if (cadence) {
            body.append('cadence', cadence.value)
        }
        await this.envoyer(body, event.currentTarget)
    }

    async envoyer(body, bouton) {
        const racine = this.contentTarget.querySelector('[data-config-url]')
        if (!racine) {
            return
        }
        body.append('_token', racine.dataset.csrf)
        if (bouton) {
            bouton.disabled = true
        }
        try {
            const reponse = await fetch(racine.dataset.configUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            })
            if (!reponse.ok) {
                throw new Error()
            }
            this.contentTarget.innerHTML = await reponse.text()
            this.rechercher()
            this.majSelection()
        } catch (e) {
            if (bouton) {
                bouton.disabled = false
            }
        }
    }

    valeur(selecteur) {
        const el = this.contentTarget.querySelector(selecteur)
        return el ? el.value : ''
    }

    // Normalise pour la recherche : minuscules, sans espaces (ex. "17185" trouve "17 185").
    norm(s) {
        return s.toLowerCase().replace(/\s+/g, '')
    }

    show() {
        // Fermé = translate-x-full (hors-écran droite) ; ouvert = -translate-x-full
        // (collé à gauche du détail, soit décalé de sa propre largeur vers la gauche).
        this.panelTarget.classList.remove('translate-x-full')
        this.panelTarget.classList.add('-translate-x-full')
        this.backdropTarget.classList.remove('hidden')
    }

    close() {
        this.panelTarget.classList.remove('-translate-x-full')
        this.panelTarget.classList.add('translate-x-full')
        this.backdropTarget.classList.add('hidden')
    }

    keydown(event) {
        if ('Escape' !== event.key) {
            return
        }
        const modal = this.contentTarget.querySelector('[data-role=msg-modal]:not(.hidden)')
        if (modal) {
            this.fermerMessage()
            return
        }
        this.close()
    }
}
