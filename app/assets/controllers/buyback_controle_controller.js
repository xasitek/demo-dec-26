import { Controller } from '@hotwired/stimulus';

/*
 * Controle Buy Back en temps reel sous la section 2 du formulaire de depot.
 *
 * Surveille l'immatriculation + le montant (rachat sec uniquement). Apres une courte
 * pause de frappe, interroge l'endpoint et injecte le fragment renvoye :
 *   - plaque connue + montant OK  -> message vert (infos vehicule + ER TTC) ;
 *   - montant > ER TTC + 3 EUR    -> alerte expliquant l'ecart ;
 *   - plaque inconnue / autre motif -> rien (conteneur vide, masque).
 */
export default class extends Controller {
    static targets = ['message'];
    static values = { url: String };

    connect() {
        this.timer = null;
    }

    disconnect() {
        if (this.timer) clearTimeout(this.timer);
    }

    // Declenche par les evenements input/change du formulaire (debounce).
    check() {
        if (this.timer) clearTimeout(this.timer);
        this.timer = setTimeout(() => this.charger(), 400);
    }

    async charger() {
        const form = this.element;
        const motif = form.querySelector('input[name="motif"]:checked')?.value;
        const immat = (form.querySelector('input[name="immatriculation"]')?.value ?? '').trim();
        const montant = (form.querySelector('input[name="montant"]')?.value ?? '').trim();

        if ('rachat_sec' !== motif || '' === immat) {
            this.vider();
            return;
        }

        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('immat', immat);
            url.searchParams.set('montant', montant);
            const reponse = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            // Non connectee : le firewall renvoie vers l'accueil (redirect suivi par fetch).
            // On affiche un message clair au lieu d'injecter la home dans la zone de controle.
            if (reponse.redirected) {
                this.messageTarget.innerHTML = '<div class="rounded-lg border border-navy/20 bg-navy/[0.06] px-3 py-2.5 text-sm text-navy">Connectez-vous pour connaître le résultat du contrôle Buy Back.</div>';
                this.messageTarget.classList.remove('hidden');
                this.bloquer(false);
                return;
            }
            if (!reponse.ok) {
                this.vider();
                return;
            }
            const html = (await reponse.text()).trim();
            this.messageTarget.innerHTML = html;
            this.messageTarget.classList.toggle('hidden', '' === html);
            // Surpaiement -> on bloque le bouton "Deposer" (le controleur depot lit ce flag).
            this.bloquer(null !== this.messageTarget.querySelector('[data-buyback-surpaiement]'));
        } catch (e) {
            this.vider();
        }
    }

    vider() {
        this.messageTarget.innerHTML = '';
        this.messageTarget.classList.add('hidden');
        this.bloquer(false);
    }

    // Pose (ou retire) le drapeau lu par remboursement-depot#evaluer, puis lui demande
    // de reevaluer l'etat du bouton "Deposer".
    bloquer(actif) {
        this.element.dataset.buybackBloque = actif ? '1' : '';
        this.dispatch('change');
    }
}
