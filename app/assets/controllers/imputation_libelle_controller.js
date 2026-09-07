import { Controller } from '@hotwired/stimulus';

/*
 * Page de verification comptable : quand la comptable saisit la cle du dossier
 * (code ICAR en trop-percu, immatriculation en rachat sec) dans la colonne "Valeur
 * validee" — typiquement parce que l'IA ne l'a pas detectee —, on reconstruit le
 * LIBELLE d'imputation a sa place, au meme format que le calcul serveur :
 *   "{MOTIF} // {cle} - {reference sans REMB-}".
 */
export default class extends Controller {
    static targets = ['cle', 'libelle', 'requis', 'valider'];
    static values = { motif: String, reference: String };

    connect() {
        this.verifierRequis();
    }

    maj() {
        if (!this.hasCleTarget || !this.hasLibelleTarget) {
            return;
        }
        const cle = this.cleTarget.value.trim();
        this.libelleTarget.value = `${this.motifValue} // ${cle} - ${this.referenceValue}`;
    }

    // Toutes les "Valeur validée" doivent être remplies pour pouvoir Valider (ex. code
    // ICAR non détecté par l'IA -> vide -> la comptable doit le saisir). Sinon bouton grisé.
    verifierRequis() {
        if (!this.hasValiderTarget) {
            return;
        }
        const manquant = this.requisTargets.some((el) => '' === el.value.trim());
        this.validerTarget.disabled = manquant;
    }
}
