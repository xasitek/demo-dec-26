import { Controller } from '@hotwired/stimulus';

/*
 * File comptable « À vérifier » : clic sur le tag « Doublon » d'une ligne -> panneau
 * glissant À DROITE listant les dossiers du groupe (même bénéficiaire) avec référence,
 * client, établissement et statut. On stoppe la propagation pour ne PAS ouvrir la page de
 * vérification (clic sur le reste de la ligne). Un clic sur un dossier de la liste ouvre
 * un MODAL CENTRAL avec son détail (fragment panneau chargé à la volée).
 */
export default class extends Controller {
    static targets = ['panneau', 'liste', 'fiche', 'ficheContenu'];
    static values = { ficheUrl: String };

    connect() {
        this.onKey = (e) => {
            if ('Escape' === e.key) {
                this.fermerFiche();
                this.fermerPanneau();
            }
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    // Clic sur le tag « Doublon » -> remplit et ouvre le panneau de droite.
    ouvrir(event) {
        event.stopPropagation();
        if (!this.hasListeTarget || !this.hasPanneauTarget) return;
        const groupe = this.lire(event.currentTarget.dataset.groupe);
        this.listeTarget.replaceChildren(...groupe.map((d) => this.item(d)));
        this.panneauTarget.classList.remove('translate-x-full');
    }

    item(d) {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.title = 'Voir le détail';
        btn.className = 'w-full rounded-lg border border-hairline bg-white px-3 py-2.5 text-left shadow-sm transition hover:border-navy/30 hover:bg-surface';
        btn.addEventListener('click', () => this.ouvrirFiche(d.id));

        const haut = document.createElement('div');
        haut.className = 'flex items-center justify-between gap-2';
        const ref = document.createElement('span');
        ref.className = 'font-mono text-xs text-navy';
        ref.textContent = d.reference || '';
        const statut = document.createElement('span');
        statut.className = 'shrink-0 rounded-full bg-ink/10 px-2 py-0.5 text-[10px] font-semibold text-ink/60';
        statut.textContent = d.statut || '';
        haut.append(ref, statut);

        const client = document.createElement('div');
        client.className = 'mt-1 text-sm font-semibold text-navy';
        client.textContent = d.client || '—';

        const etab = document.createElement('div');
        etab.className = 'text-xs text-ink/55';
        etab.textContent = d.etablissement || '—';

        btn.append(haut, client, etab);
        li.appendChild(btn);
        return li;
    }

    fermerPanneau() {
        if (this.hasPanneauTarget) this.panneauTarget.classList.add('translate-x-full');
    }

    // Clic sur un dossier de la liste -> modal central avec son détail (panneau à la volée).
    async ouvrirFiche(id) {
        if (!id || !this.hasFicheTarget || !this.ficheUrlValue) return;
        this.ficheContenuTarget.innerHTML = '<p class="px-5 py-10 text-center text-sm text-ink/55">Chargement…</p>';
        this.ficheTarget.classList.remove('hidden');
        this.ficheTarget.classList.add('flex');
        try {
            const reponse = await fetch(this.ficheUrlValue.replace('__ID__', String(id)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            this.ficheContenuTarget.innerHTML = reponse.ok
                ? await reponse.text()
                : '<p class="px-5 py-10 text-center text-sm text-negative">Erreur de chargement.</p>';
        } catch (e) {
            this.ficheContenuTarget.innerHTML = '<p class="px-5 py-10 text-center text-sm text-negative">Erreur réseau.</p>';
        }
    }

    fermerFiche() {
        if (!this.hasFicheTarget) return;
        this.ficheTarget.classList.add('hidden');
        this.ficheTarget.classList.remove('flex');
    }

    lire(json) {
        try {
            const v = JSON.parse(json || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) {
            return [];
        }
    }
}
