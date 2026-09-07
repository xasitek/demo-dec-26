import { Controller } from '@hotwired/stimulus'

/*
 * Liste des retours GROUPEE PAR CONVERSATION (un client = une ligne).
 * Insertion / mise a jour temps reel : un nouveau message d'un client deja liste
 * met a jour sa ligne (et la remonte) ; un nouveau client cree une ligne. Une
 * conversation traitee (repondu / cloture) sort de la liste.
 *
 * L'insertion ecoute "recouvrement:retour" (emis par le controleur partage
 * "realtime", une seule connexion Mercure). On recupere la LIGNE DE CONVERSATION
 * rendue cote serveur et on la place en tete.
 */
export default class extends Controller {
    static targets = ['items', 'tableau', 'vide', 'compteur']
    static values = {
        rowUrl: String,
        restant: Number,
        labelSingulier: String,
        labelPluriel: String,
    }

    // --- Insertion / mise a jour temps reel ----------------------------------

    async nouveau(event) {
        const id = event.detail && event.detail.id
        if (!id || !this.hasItemsTarget) return

        try {
            const res = await fetch(this.rowUrlValue.replace('__ID__', id), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            if (!res.ok || 204 === res.status) return
            const html = (await res.text()).trim()
            if ('' === html) return

            const tmp = document.createElement('tbody')
            tmp.innerHTML = html
            const nouvelle = tmp.querySelector('tr[data-conversation-cle]')
            if (!nouvelle) return
            const cle = nouvelle.getAttribute('data-conversation-cle')

            // Meme client deja liste -> remplacement (compteur inchange) ; sinon
            // nouvelle conversation -> +1 au compteur "a traiter".
            const existante = this.itemsTarget.querySelector(`tr[data-conversation-cle="${CSS.escape(cle)}"]`)
            if (existante) {
                existante.remove()
            } else {
                this.restantValue = this.restantValue + 1
            }

            this.itemsTarget.insertAdjacentHTML('afterbegin', nouvelle.outerHTML)
            this.synchroniser()
            const row = this.itemsTarget.querySelector(`tr[data-conversation-cle="${CSS.escape(cle)}"]`)
            if (row) this.surligner(row)
        } catch (e) {
            // silencieux : le rechargement montrera la conversation de toute facon
        }
    }

    // Une conversation vient d'etre traitee (reponse envoyee ou cloture) depuis le
    // panneau detail : elle sort de la liste "a traiter". Retrait par cle de conversation.
    conversationTraitee(event) {
        const cle = event.detail && event.detail.cle
        if (!cle || !this.hasItemsTarget) return
        const row = this.itemsTarget.querySelector(`tr[data-conversation-cle="${CSS.escape(cle)}"]`)
        if (row) this.retirer(row)
    }

    // --- Utilitaires ---------------------------------------------------------

    // Affiche le tableau ou l'etat vide selon le nombre de retours restants, et
    // tient le compteur "N retour(s) a traiter" a jour (source de verite : restant).
    synchroniser() {
        const vide = this.restantValue <= 0
        if (this.hasTableauTarget) this.tableauTarget.style.display = vide ? 'none' : ''
        if (this.hasVideTarget) this.videTarget.style.display = vide ? '' : 'none'
        if (this.hasCompteurTarget) {
            this.compteurTarget.style.display = vide ? 'none' : ''
            if (!vide) this.compteurTarget.textContent = this.texteCompteur(this.restantValue)
        }
    }

    texteCompteur(n) {
        const mot = Math.abs(n) > 1 ? this.labelPlurielValue : this.labelSingulierValue
        return `${n} ${mot}`
    }

    retirer(row) {
        if (!row) return
        row.style.transition = 'opacity .2s ease'
        row.style.opacity = '0'
        window.setTimeout(() => {
            row.remove()
            this.restantValue = Math.max(0, this.restantValue - 1)
            this.synchroniser()
        }, 200)
    }

    surligner(row) {
        row.style.transition = 'background-color 1.4s ease'
        row.style.backgroundColor = 'rgba(200, 170, 115, 0.25)'
        window.setTimeout(() => { row.style.backgroundColor = '' }, 1400)
    }
}
