import { Controller } from '@hotwired/stimulus'

/*
 * Dépôt de remboursement :
 *  - connexion Google en POPUP (la secrétaire n'est pas connectée) ; à la réussite,
 *    on affiche "Bonjour Prénom" + "Connexion établie" SANS recharger la page ;
 *  - choix du motif (aucun par défaut) qui révèle le formulaire ;
 *  - combobox établissement (recherche code/nom, soumet le code) ;
 *  - le bouton "Déposer" reste désactivé tant que : pas connectée, motif non choisi,
 *    ou un champ/pièce requis vide. La sécurité réelle est côté serveur.
 */

// Garde-fou d'affichage de la combobox : largement au-dessus du referentiel actuel
// (une centaine d'etablissements), et son depassement est ANNONCE dans le menu — un
// plafond silencieux masquait des etablissements sans que personne le voie.
const MAX_SUGGESTIONS_ETAB = 300

export default class extends Controller {
    static targets = ['motif', 'corps', 'bloc', 'submit', 'etabInput', 'etabValue', 'etabMenu',
        'banniere', 'accueil', 'avatar', 'prenom', 'hintConnexion',
        'erreurs', 'submitLabel', 'spinner', 'submitArrow']
    static values = { authentifie: Boolean, etabs: Array, moiUrl: String }

    connect() {
        this.onDocClick = (e) => { if (!this.element.contains(e.target)) this.fermerEtab() }
        document.addEventListener('click', this.onDocClick)
        this.onMessage = (e) => {
            if (e.origin !== window.location.origin) return
            if (e.data && e.data.type === 'remb-auth-ok') this.confirmerConnexion()
        }
        window.addEventListener('message', this.onMessage)
        this.evaluer()
    }

    disconnect() {
        document.removeEventListener('click', this.onDocClick)
        window.removeEventListener('message', this.onMessage)
    }

    // --- Connexion Google en popup ---
    connexionPopup(event) {
        const url = event.currentTarget.dataset.url
        const popup = window.open(url, 'oauth-google', 'popup,width=500,height=650')
        if (!popup) { window.location.href = event.currentTarget.dataset.fallback || url; return }
        // Repli : si le popup se ferme sans message, on vérifie quand même la session.
        const timer = setInterval(() => {
            if (popup.closed) { clearInterval(timer); this.confirmerConnexion() }
        }, 800)
    }

    confirmerConnexion() {
        if (this.authentifieValue || !this.hasMoiUrlValue) return
        fetch(this.moiUrlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => {
                if (!data) return
                this.authentifieValue = true
                if (this.hasPrenomTarget) this.prenomTarget.textContent = data.prenom || ''
                if (this.hasAvatarTarget && data.avatar) {
                    this.avatarTarget.src = data.avatar
                    this.avatarTarget.classList.remove('hidden')
                }
                if (this.hasBanniereTarget) this.banniereTarget.classList.add('hidden')
                if (this.hasAccueilTarget) { this.accueilTarget.classList.remove('hidden'); this.accueilTarget.classList.add('flex') }
                if (this.hasHintConnexionTarget) this.hintConnexionTarget.classList.add('hidden')
                this.evaluer()
            })
            .catch(() => {})
    }

    // --- Soumission AJAX : spinner, succès (redirection + notif) ou erreurs en direct ---
    soumettre(event) {
        event.preventDefault()
        if (this.hasSubmitTarget && this.submitTarget.disabled) return
        this.effacerErreurs()
        this.spinner(true)

        const form = this.element
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json().catch(() => null))
            .then((data) => {
                if (data && data.ok) { window.location.href = data.redirect; return }
                this.spinner(false)
                this.afficherErreurs((data && data.erreurs) || ['Une erreur est survenue. Réessayez.'])
            })
            .catch(() => {
                this.spinner(false)
                this.afficherErreurs(['Connexion interrompue. Vérifiez votre réseau et réessayez.'])
            })
    }

    spinner(on) {
        if (this.hasSubmitTarget) this.submitTarget.disabled = on
        if (this.hasSubmitLabelTarget) this.submitLabelTarget.textContent = on ? 'Dépôt en cours…' : 'Déposer le dossier'
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.toggle('hidden', !on)
        if (this.hasSubmitArrowTarget) this.submitArrowTarget.classList.toggle('hidden', on)
    }

    effacerErreurs() {
        if (this.hasErreursTarget) { this.erreursTarget.classList.add('hidden'); this.erreursTarget.innerHTML = '' }
    }

    afficherErreurs(liste) {
        if (!this.hasErreursTarget) return
        const items = liste.map((m) => '<li>' + this.echapper(m) + '</li>').join('')
        this.erreursTarget.innerHTML = '<p class="mb-1 font-semibold">Le formulaire comporte une erreur :</p><ul class="list-disc space-y-0.5 pl-5">' + items + '</ul>'
        this.erreursTarget.classList.remove('hidden')
        this.erreursTarget.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }

    echapper(s) {
        const d = document.createElement('div')
        d.textContent = s
        return d.innerHTML
    }

    motifChoisi() {
        const coche = this.motifTargets.find((r) => r.checked)
        return coche ? coche.value : ''
    }

    // --- Combobox établissement : clic = liste complète (select), frappe = filtre ---
    ouvrirEtab() {
        this.rendreEtab(this.etabsValue)
    }

    filtrerEtab() {
        const q = this.etabInputTarget.value.trim().toLowerCase()
        this.etabValueTarget.value = ''
        this.rendreEtab(this.etabsValue.filter((e) => (e.code + ' ' + e.label).toLowerCase().includes(q)))
        this.evaluer()
    }

    rendreEtab(matches) {
        this.etabMenuTarget.innerHTML = ''
        if (matches.length === 0) { this.fermerEtab(); return }

        // Le menu defile (max-h-60 + overflow-auto) : on rend TOUTE la liste. Un plafond
        // silencieux masquait les etablissements au-dela du 50e dans l'ordre alphabetique.
        // La borne ne sert que de garde-fou si le referentiel explose, et elle se voit.
        const visibles = matches.slice(0, MAX_SUGGESTIONS_ETAB)
        for (const e of visibles) {
            const li = document.createElement('li')
            li.className = 'cursor-pointer px-3 py-2 text-sm text-ink transition hover:bg-navy hover:text-white'
            // echapper() : les libelles viennent du referentiel, editable en base.
            li.innerHTML = '<span class="font-mono text-xs opacity-70">' + this.echapper(e.code) + '</span> &nbsp; '
                + this.echapper(e.label)
            li.addEventListener('mousedown', (ev) => { ev.preventDefault(); this.choisirEtab(e) })
            this.etabMenuTarget.appendChild(li)
        }

        if (matches.length > visibles.length) {
            const reste = document.createElement('li')
            reste.className = 'border-t border-hairline px-3 py-2 text-xs text-ink/50'
            reste.textContent = `+ ${matches.length - visibles.length} autres — précisez votre recherche`
            this.etabMenuTarget.appendChild(reste)
        }

        this.etabMenuTarget.hidden = false
    }

    choisirEtab(e) {
        this.etabInputTarget.value = e.code + ' — ' + e.label
        this.etabValueTarget.value = e.code
        this.fermerEtab()
        this.evaluer()
    }

    fermerEtab() {
        if (this.hasEtabMenuTarget) this.etabMenuTarget.hidden = true
    }

    // --- Aperçu du fichier joint (nom + état "joint") ---
    fichier(event) {
        const input = event.target
        const label = input.closest('label')
        if (!label) return
        const nom = label.querySelector('[data-nom]')
        const icone = label.querySelector('[data-icone]')
        const f = input.files[0]

        // Hors « petits comptes » (data-convertible) : seuls PDF et images sont acceptés.
        const nomF = (f ? f.name : '').toLowerCase()
        const estPdfImage = f && (f.type === 'application/pdf' || f.type.startsWith('image/') || /\.(pdf|png|jpe?g|gif|webp|heic|bmp|tiff?)$/.test(nomF))
        if (f && input.dataset.convertible !== '1' && !estPdfImage) {
            input.value = ''
            if (nom) { nom.textContent = 'Format refusé — PDF ou image uniquement.'; nom.classList.add('text-negative'); nom.classList.remove('text-positive', 'font-medium') }
            if (icone) icone.classList.remove('bg-positive/10', 'text-positive')
            this.evaluer()
            return
        }

        const ok = input.files.length > 0
        if (nom) {
            nom.textContent = ok ? f.name : 'Aucun fichier sélectionné'
            nom.classList.toggle('text-positive', ok)
            nom.classList.remove('text-negative')
            nom.classList.toggle('font-medium', ok)
        }
        if (icone) {
            icone.classList.toggle('bg-positive/10', ok)
            icone.classList.toggle('text-positive', ok)
        }
        this.evaluer()
    }

    evaluer() {
        const motif = this.motifChoisi()

        if (this.hasCorpsTarget) this.corpsTarget.classList.toggle('hidden', motif === '')
        this.blocTargets.forEach((bloc) => {
            const cache = bloc.dataset.motif !== motif
            bloc.classList.toggle('hidden', cache)
            // Champs des blocs masqués désactivés -> exclus de l'envoi. Évite la collision
            // de noms de pièces communes aux 2 motifs (ex. "rib"), qui vidait le fichier
            // reçu côté serveur ("Pièce manquante : RIB").
            bloc.querySelectorAll('input, select, textarea').forEach((el) => { el.disabled = cache })
        })

        // Mise en page dépendante du motif : les classes listées dans
        // `data-motif-classe` sont posées quand le motif courant figure dans
        // `data-motif-classe-si`. Sert aux champs COMMUNS aux deux motifs dont seule la
        // largeur ou le rang change — les dupliquer par motif créerait deux entrées de
        // même nom. Rien de codé en dur ici : les motifs sont écrits dans le gabarit.
        this.element.querySelectorAll('[data-motif-classe]').forEach((el) => {
            const actif = (el.dataset.motifClasseSi || '').split(' ').includes(motif)
            el.dataset.motifClasse.split(' ').forEach((c) => el.classList.toggle(c, actif))
        })

        let ok = this.authentifieValue && motif !== ''
        if (ok && this.hasEtabValueTarget && this.etabValueTarget.value.trim() === '') ok = false
        if (ok) {
            for (const el of this.element.querySelectorAll('[data-req]')) {
                if (el.type === 'radio') continue
                const bloc = el.closest('[data-motif]')
                if (bloc && bloc.dataset.motif !== motif) continue
                if (el.type === 'file') {
                    if (el.files.length === 0) { ok = false; break }
                } else if (el.value.trim() === '') {
                    ok = false; break
                }
            }
        }
        // Blocage Buy Back (surpaiement rachat sec) : le contrôleur buyback pose ce flag.
        if (this.element.dataset.buybackBloque === '1') ok = false
        if (this.hasSubmitTarget) this.submitTarget.disabled = !ok
    }
}
