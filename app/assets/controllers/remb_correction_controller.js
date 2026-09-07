import { Controller } from '@hotwired/stimulus';

/*
 * Fiche de correction (secrétaire) : grise le bouton « Renvoyer le dossier corrigé »
 * tant qu'un champ client requis est vide OU qu'une pièce signalée « à corriger » n'a
 * pas été re-jointe. Même logique de garde qu'au dépôt ; la sécurité réelle reste côté
 * serveur. Les champs texte sont pré-remplis : le blocage vient surtout des pièces à
 * remplacer (fichiers vides au chargement).
 */
export default class extends Controller {
    static targets = ['submit'];

    connect() {
        this.evaluer();
    }

    evaluer() {
        let ok = true;
        for (const el of this.element.querySelectorAll('[data-req]')) {
            if ('file' === el.type) {
                if (0 === el.files.length) { ok = false; break; }
            } else if ('' === el.value.trim()) {
                ok = false;
                break;
            }
        }
        if (this.hasSubmitTarget) this.submitTarget.disabled = !ok;
    }
}
