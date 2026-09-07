import { Controller } from '@hotwired/stimulus';

/*
 * Ecran de verification (module Remboursement) : surveille la VALEUR RETENUE.
 *
 * C'est elle qui alimente le virement SEPA et l'ecriture comptable. Un caractere de
 * travers frappe ici -- AGRIFRPP825 pour AGRIFRPP826 -- partait sans le moindre signal,
 * les colonnes Saisie et IA etant, elles, d'accord entre elles.
 *
 * Le champ vire au rouge des qu'il s'ecarte de ce qui a ete lu (valeur IA, ou la saisie
 * a defaut). Le meme controle est fait au rendu cote serveur : ici il ne fait que
 * repondre pendant la frappe, avant l'enregistrement.
 *
 * La normalisation reprend EXACTEMENT les regles de NormalisateurControle (PHP) : sur un
 * identifiant seuls les caracteres alphanumeriques comptent, sur du texte libre les
 * espaces sont reduits et la casse ignoree. Les deux doivent rester d'accord, sans quoi
 * le champ changerait d'avis au rechargement.
 */
export default class extends Controller {
    static targets = ['champ', 'note'];
    static values = { reference: String, identifiant: String };

    static ROUGE = ['border-negative', 'bg-negative/[0.04]', 'font-semibold', 'text-negative', 'focus:border-negative', 'focus:ring-negative/15'];
    static NEUTRE = ['border-hairline', 'text-ink', 'focus:border-navy', 'focus:ring-navy/15'];

    verifier() {
        if (!this.hasChampTarget) return;

        const saisi = this.normaliser(this.champTarget.value);
        const lu = this.normaliser(this.referenceValue);
        const ecart = '' !== saisi && '' !== lu && saisi !== lu;

        this.constructor.ROUGE.forEach((c) => this.champTarget.classList.toggle(c, ecart));
        this.constructor.NEUTRE.forEach((c) => this.champTarget.classList.toggle(c, !ecart));
        if (this.hasNoteTarget) this.noteTarget.hidden = !ecart;
    }

    normaliser(valeur) {
        const v = String(valeur ?? '');

        return '1' === this.identifiantValue
            ? v.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
            : v.replace(/\s+/g, ' ').trim().toUpperCase();
    }
}
