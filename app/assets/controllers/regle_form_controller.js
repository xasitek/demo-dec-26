import { Controller } from '@hotwired/stimulus'

/*
 * Constructeur de filtres d'une règle de relance : ajout / retrait de lignes
 * (champ, opérateur, valeur), APERÇU LIVE du périmètre (nb comptes / factures /
 * encours) recalculé côté serveur, et CHOIX DE VALEUR ADAPTÉ au champ :
 *
 *  - champ catégoriel (famille, conditions/mode de règlement, collectif, marque…)
 *    + opérateur = / ≠      -> <select> simple (options = valeurs présentes en base,
 *    + opérateur dans/pas dans -> <select> multiple    affichées « code — libellé »)
 *  - sinon (numérique, texte, contient, commence par) -> saisie libre.
 *
 * Le gabarit d'une ligne vierge est un <template> rendu côté serveur ; à l'ajout on
 * clone en remplaçant __IDX__ par un index unique pour que PHP regroupe bien
 * {champ, operateur, valeur} par ligne. Les valeurs déjà saisies sont préservées
 * quand on change de champ/opérateur.
 */
export default class extends Controller {
    static targets = ['lignes', 'gabarit', 'form', 'apercuComptes', 'apercuFactures', 'apercuEncours']
    static values = { apercuUrl: String, options: Object, categoriels: Array }

    connect() {
        this.compteur = 0
        // Les lignes existantes sont rendues côté serveur avec un <input> ; on les
        // convertit en select là où c'est pertinent (en préservant la valeur).
        this.lignesTarget.querySelectorAll('[data-regle-form-target="ligne"]').forEach((l) => this.majValeur(l))
        this.rafraichir()
    }

    ajouter() {
        const html = this.gabaritTarget.innerHTML.replaceAll('__IDX__', 'n' + this.compteur++)
        this.lignesTarget.insertAdjacentHTML('beforeend', html)
        const ligne = this.lignesTarget.lastElementChild
        if (ligne) this.majValeur(ligne)
        this.rafraichir()
    }

    retirer(event) {
        const ligne = event.target.closest('[data-regle-form-target="ligne"]')
        if (ligne) {
            ligne.remove()
            this.rafraichir()
        }
    }

    // Changement de champ ou d'opérateur : réajuste le contrôle de valeur puis l'aperçu.
    changer(event) {
        const ligne = event.target.closest('[data-regle-form-target="ligne"]')
        if (ligne) this.majValeur(ligne)
        this.rafraichir()
    }

    // Rafraîchissement différé (frappe au clavier dans un champ valeur libre).
    rafraichirDiffere() {
        window.clearTimeout(this.timer)
        this.timer = window.setTimeout(() => this.rafraichir(), 350)
    }

    // Remplace le contrôle "valeur" d'une ligne par le bon type (select mono, select
    // multiple, ou saisie libre) selon le champ et l'opérateur, en préservant la valeur.
    majValeur(ligne) {
        const champSel = ligne.querySelector('select[name$="[champ]"]')
        const opSel = ligne.querySelector('select[name$="[operateur]"]')
        const ancien = ligne.querySelector('[name*="[valeur]"]')
        if (!champSel || !opSel || !ancien) return

        const champ = champSel.value
        const operateur = opSel.value
        const idx = this.indexDepuis(champSel.getAttribute('name'))
        const valeurs = this.valeursActuelles(ancien)

        const categoriel = this.categorielsValue.includes(champ) && ['eq', 'neq', 'in', 'notin'].includes(operateur)
        const multiple = ['in', 'notin'].includes(operateur)

        const control = categoriel
            ? this.construireSelect(idx, champ, multiple, valeurs)
            : this.construireInput(idx, valeurs)

        ancien.replaceWith(control)
    }

    construireSelect(idx, champ, multiple, valeurs) {
        const sel = document.createElement('select')
        sel.name = 'filtres[' + idx + '][valeur]' + (multiple ? '[]' : '')
        sel.className = 'fc-input min-w-[10rem] flex-1 text-sm'
        sel.setAttribute('data-action', 'change->regle-form#rafraichir')

        const options = this.optionsValue[champ] || []
        if (multiple) {
            sel.multiple = true
            sel.size = Math.min(6, Math.max(3, options.length))
        } else {
            const vide = document.createElement('option')
            vide.value = ''
            vide.textContent = '—'
            sel.appendChild(vide)
        }

        for (const opt of options) {
            const o = document.createElement('option')
            o.value = opt.code
            o.textContent = opt.libelle && opt.libelle !== opt.code ? opt.code + ' — ' + opt.libelle : opt.code
            if (valeurs.includes(opt.code)) o.selected = true
            sel.appendChild(o)
        }
        return sel
    }

    construireInput(idx, valeurs) {
        const input = document.createElement('input')
        input.type = 'text'
        input.name = 'filtres[' + idx + '][valeur]'
        input.className = 'fc-input min-w-[10rem] flex-1 text-sm'
        input.placeholder = 'valeur (liste : séparer par des virgules)'
        input.setAttribute('data-action', 'input->regle-form#rafraichirDiffere')
        input.value = valeurs.join(', ')
        return input
    }

    // Valeur(s) courante(s) du contrôle, quelle que soit sa forme.
    valeursActuelles(el) {
        if (el.tagName === 'SELECT' && el.multiple) {
            return Array.from(el.selectedOptions).map((o) => o.value).filter((v) => v !== '')
        }
        const v = (el.value || '').trim()
        if (v === '') return []
        return v.split(',').map((s) => s.trim()).filter((s) => s !== '')
    }

    indexDepuis(name) {
        const m = (name || '').match(/filtres\[([^\]]+)\]/)
        return m ? m[1] : 'n' + this.compteur++
    }

    async rafraichir() {
        try {
            const res = await fetch(this.apercuUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(this.formTarget),
            })
            if (!res.ok) return
            const data = await res.json()
            this.apercuComptesTarget.textContent = this.nombre(data.comptes)
            this.apercuFacturesTarget.textContent = this.nombre(data.factures)
            this.apercuEncoursTarget.textContent = this.euros(data.encours)
        } catch (e) {
            // silencieux : l'aperçu est indicatif
        }
    }

    nombre(n) {
        return new Intl.NumberFormat('fr-FR').format(Number(n) || 0)
    }

    euros(v) {
        return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0))
    }
}
