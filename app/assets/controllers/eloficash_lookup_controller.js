import { Controller } from '@hotwired/stimulus';

/*
 * Aide au dépôt TROP-PERÇU : cherche un client (nom ou code ICAR) et préremplit le montant
 * (= solde NET créditeur du compte) + le code ICAR (= n° de compte) + le nom. L'IBAN vient
 * du RIB (absent de la base). Tout reste modifiable ; saisie manuelle possible.
 *
 * Tant que le préremplissage n'est pas modifié, le relevé ICAR et les petits comptes sont
 * CACHÉS et non requis (la donnée vient de la base). Dès qu'on modifie le montant ou l'ICAR,
 * ils redeviennent requis (le serveur re-vérifie de son côté).
 */
export default class extends Controller {
    static targets = ['recherche', 'menu', 'lignes', 'info'];
    static values = { rechercheUrl: String, donneesUrl: String };

    connect() {
        this.timer = null;
        this.prefill = null;
        this.dehors = (e) => { if (!this.element.contains(e.target)) this.fermerMenu(); };
        document.addEventListener('click', this.dehors);

        // Si la secrétaire modifie le montant ou l'ICAR préremplis, réévaluer les pièces.
        this.surChamp = () => this.majPieces();
        this.champsSurveilles().forEach((el) => el.addEventListener('input', this.surChamp));
    }

    disconnect() {
        if (this.timer) clearTimeout(this.timer);
        document.removeEventListener('click', this.dehors);
        if (this.surChamp) this.champsSurveilles().forEach((el) => el.removeEventListener('input', this.surChamp));
    }

    chercher() {
        if (this.timer) clearTimeout(this.timer);
        const q = this.rechercheTarget.value.trim();
        if (q.length < 2) { this.fermerMenu(); return; }
        this.timer = setTimeout(() => this.lancerRecherche(q), 300);
    }

    // Entrée dans le champ de recherche ne doit PAS soumettre le dépôt.
    toucheClavier(event) {
        if ('Enter' === event.key) event.preventDefault();
    }

    async lancerRecherche(q) {
        try {
            const url = new URL(this.rechercheUrlValue, window.location.origin);
            url.searchParams.set('q', q);
            const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (r.redirected || !r.ok) { this.fermerMenu(); return; }
            const data = await r.json();
            this.afficherMenu(Array.isArray(data.clients) ? data.clients : []);
        } catch (e) {
            this.fermerMenu();
        }
    }

    afficherMenu(clients) {
        this.menuTarget.replaceChildren();
        if (0 === clients.length) {
            const li = document.createElement('li');
            li.className = 'px-3 py-2 text-sm text-ink/50';
            li.textContent = 'Aucun client trouvé.';
            this.menuTarget.appendChild(li);
        } else {
            clients.forEach((c) => {
                const li = document.createElement('li');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'block w-full px-3 py-2 text-left text-sm transition hover:bg-surface';
                const nom = document.createElement('span');
                nom.className = 'block font-medium text-navy';
                nom.textContent = c.client || '—';
                const sous = document.createElement('span');
                sous.className = 'block text-xs text-ink/45';
                sous.textContent = [c.ville, c.compte].filter(Boolean).join(' · ');
                btn.append(nom, sous);
                btn.addEventListener('click', () => this.choisirClient(c.compte, c.client));
                li.appendChild(btn);
                this.menuTarget.appendChild(li);
            });
        }
        this.menuTarget.hidden = false;
    }

    fermerMenu() {
        this.menuTarget.hidden = true;
        this.menuTarget.replaceChildren();
    }

    async choisirClient(compte, client) {
        this.rechercheTarget.value = client || '';
        this.fermerMenu();
        if (client) this.remplir('nom_client', client);

        try {
            const url = new URL(this.donneesUrlValue, window.location.origin);
            url.searchParams.set('compte', compte);
            const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!r.ok) return;
            const d = await r.json();
            if (d.iban) this.remplir('iban_client', d.iban);
            if (d.bic) this.remplir('bic_client', d.bic);
            this.appliquerTropPercu(d.tropPercu || null, compte);
        } catch (e) {
            // silencieux : la secrétaire complète à la main.
        }
    }

    // Trop-perçu = solde NET créditeur du compte : préremplit montant + code ICAR (= n° de
    // compte) et mémorise les valeurs (pour la bascule des pièces).
    appliquerTropPercu(tp, compte) {
        this.lignesTarget.replaceChildren();
        this.lignesTarget.hidden = true;

        if (!tp || !tp.credit) {
            this.prefill = null;
            this.setCompte('');
            this.majPieces();
            this.info(
                !tp
                    ? 'Aucune donnée trouvée pour ce client — saisie manuelle.'
                    : `Ce compte n'est pas en solde créditeur (net ${this.montantFr(tp.net)} €) — vérifiez, ou saisissez à la main.`,
                'attention',
            );
            return;
        }

        this.remplir('montant', String(tp.montant).replace('.', ','));
        if (tp.icar) this.remplir('code_icar', tp.icar);

        this.prefill = { montant: this.norme(tp.montant), icar: this.digits(tp.icar) };
        this.setCompte(compte || '');
        this.majPieces();

        const box = document.createElement('div');
        box.className = 'flex items-center justify-between gap-3 rounded-lg border border-hairline bg-white px-3 py-2 text-sm';
        const g = document.createElement('span');
        g.className = 'font-semibold text-navy';
        g.textContent = tp.icar ? `Trop-perçu · ICAR ${tp.icar}` : 'Trop-perçu (solde créditeur)';
        const m = document.createElement('span');
        m.className = 'font-bold text-navy';
        m.textContent = `${this.montantFr(tp.montant)} €`;
        box.append(g, m);
        this.lignesTarget.appendChild(box);
        this.lignesTarget.hidden = false;

        this.info("Prérempli automatiquement — le relevé de compte ICAR n'est plus requis. Vérifiez et complétez l'IBAN.", 'ok');
    }

    setCompte(v) {
        const el = this.champ('eloficash_compte');
        if (el) el.value = v;
    }

    // Relevé ICAR + petits comptes : cachés/facultatifs tant que le préremplissage n'est pas
    // modifié ; visibles/requis sinon (ou en saisie manuelle).
    majPieces() {
        // Seul le relevé de compte ICAR devient facultatif ; les petits comptes restent requis.
        const optionnel = null !== this.prefill && this.correspond();
        this.togglePiece('releve_icar', optionnel);
        const form = this.element.closest('form');
        if (form) form.dispatchEvent(new Event('input', { bubbles: true }));
    }

    correspond() {
        if (null === this.prefill) return false;
        const m = this.norme((this.champ('montant') || {}).value || '');
        const i = this.digits((this.champ('code_icar') || {}).value || '');
        return m === this.prefill.montant && i === this.prefill.icar;
    }

    togglePiece(nom, optionnel) {
        const input = this.champ(nom);
        const label = input ? input.closest('label') : null;
        if (!input || !label) return;
        label.hidden = optionnel;
        if (optionnel) input.removeAttribute('data-req');
        else input.setAttribute('data-req', '');
    }

    remplir(nom, valeur) {
        const el = this.champ(nom);
        if (el) {
            el.value = valeur;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    champ(nom) {
        const form = this.element.closest('form');
        return form ? form.querySelector(`[name="${nom}"]`) : null;
    }

    champsSurveilles() {
        return ['montant', 'code_icar'].map((n) => this.champ(n)).filter(Boolean);
    }

    montantFr(v) {
        const n = Number(v);
        return Number.isFinite(n) ? n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : String(v);
    }

    norme(v) {
        const n = Number(String(v).replace(/\s/g, '').replace(',', '.'));
        return Number.isFinite(n) ? n.toFixed(2) : '';
    }

    digits(v) {
        return String(v).replace(/\D+/g, '');
    }

    info(texte, niveau) {
        const couleurs = { ok: 'text-positive', attention: 'text-gold', neutre: 'text-ink/55' };
        this.infoTarget.textContent = texte;
        this.infoTarget.className = `mt-2 text-xs ${couleurs[niveau] || 'text-ink/55'}`;
        this.infoTarget.hidden = false;
    }
}
