import { Controller } from '@hotwired/stimulus';

/*
 * Page de verification comptable : modal de message ouvert par "Refuser" ou "Demander
 * une correction". La comptable y saisit un message. L'action reelle (transition +
 * envoi) sera branchee au back ensuite ; ici on gere seulement l'ouverture/fermeture.
 */
export default class extends Controller {
    static targets = ['overlay', 'title', 'texte', 'confirmer', 'pieces'];
    static values = { corrigerUrl: String, refuserUrl: String };

    connect() {
        this.esc = (e) => {
            if ('Escape' === e.key) this.close();
        };
    }

    disconnect() {
        document.removeEventListener('keydown', this.esc);
    }

    open(event) {
        const refus = 'refus' === event.params.type;
        this.titleTarget.textContent = refus ? 'Refuser le dossier' : 'Demander une correction';
        if (this.hasConfirmerTarget) {
            this.confirmerTarget.textContent = refus ? 'Refuser le dossier' : 'Envoyer la demande';
            // Le bouton Confirmer (submit) poste le form vers refuser / corriger.
            this.confirmerTarget.formAction = refus ? this.refuserUrlValue : this.corrigerUrlValue;
        }
        // Cases "documents a corriger" : uniquement pour une demande de correction.
        if (this.hasPiecesTarget) {
            this.piecesTarget.classList.toggle('hidden', refus);
        }
        // Gabarit pre-rempli : le curseur se place sur la ligne vide entre l'accroche et
        // la signature (elle ecrit "au milieu").
        const avant = 'Bonjour,\n\n';
        const apres = '\n\nCordialement,\nService Remboursement client';
        this.texteTarget.value = avant + apres;

        // bascule hidden -> flex (sinon display:block et le centrage flex ne s'applique pas)
        this.overlayTarget.classList.remove('hidden');
        this.overlayTarget.classList.add('flex');
        document.addEventListener('keydown', this.esc);
        this.texteTarget.focus();
        this.texteTarget.setSelectionRange(avant.length, avant.length);
    }

    close() {
        this.overlayTarget.classList.add('hidden');
        this.overlayTarget.classList.remove('flex');
        document.removeEventListener('keydown', this.esc);
    }

    // Clic sur le fond (hors carte) : ferme.
    backdrop(event) {
        if (event.target === this.overlayTarget) this.close();
    }
}
