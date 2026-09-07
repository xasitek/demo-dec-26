import { Controller } from '@hotwired/stimulus';

/*
 * Journal des paiements (directeur du pôle). Confirmation SANS rechargement :
 *  - « Confirmer les paiements » d'un jour poste les dossiers COCHÉS -> ils passent en
 *    « Payé » côté serveur (temps réel pour secrétaire/comptabilité via Mercure), puis on
 *    met à jour les lignes et le badge de la section en direct.
 *  - Si seuls certains sont cochés : la section devient « Payé partiellement » (autre couleur).
 *  - Case d'en-tête : coche/décoche tout le jour.
 */
export default class extends Controller {
    static values = { confirmerUrl: String, token: String };

    toutCocher(event) {
        const section = this.section(event.currentTarget.dataset.jour);
        if (!section) return;
        const coche = event.currentTarget.checked;
        section.querySelectorAll('input[name="ids[]"]').forEach((c) => { c.checked = coche; });
    }

    async confirmer(event) {
        const bouton = event.currentTarget;
        const section = this.section(bouton.dataset.jour);
        if (!section) return;
        const ids = [...section.querySelectorAll('input[name="ids[]"]:checked')].map((c) => c.value);
        if (0 === ids.length) return;

        const label = bouton.innerHTML;
        bouton.disabled = true;
        bouton.textContent = 'Confirmation…';

        const body = new FormData();
        body.append('_token', this.tokenValue);
        ids.forEach((id) => body.append('ids[]', id));

        try {
            const reponse = await fetch(this.confirmerUrlValue, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await reponse.json().catch(() => null);
            if (!data || !data.ok) {
                bouton.disabled = false;
                bouton.innerHTML = label;
                return;
            }
            for (const id of data.payes) {
                const row = section.querySelector(`tr[data-dossier-id="${id}"]`);
                if (row) this.marquerPaye(row);
            }
            this.majSection(section, bouton, label);
        } catch (e) {
            bouton.disabled = false;
            bouton.innerHTML = label;
        }
    }

    marquerPaye(row) {
        row.dataset.etat = 'paye';
        const cell = row.querySelector('[data-role="coche"]');
        if (cell) {
            cell.innerHTML = '<span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-700"><svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>Payé</span>';
        }
    }

    majSection(section, bouton, label) {
        const attente = section.querySelectorAll('tr[data-etat="attente"]').length;
        const payes = section.querySelectorAll('tr[data-etat="paye"]').length;

        const badge = section.querySelector('[data-role="badge-section"]');
        if (badge) {
            badge.className = 'rounded-full px-2 py-0.5 text-[10px] font-semibold';
            if (0 === attente) {
                badge.textContent = 'Payé';
                badge.classList.add('bg-emerald-100', 'text-emerald-700');
            } else if (payes > 0) {
                badge.textContent = 'Payé partiellement';
                badge.classList.add('bg-orange-100', 'text-orange-700');
            } else {
                badge.textContent = 'En attente de paiement';
                badge.classList.add('bg-amber-100', 'text-amber-800');
            }
        }

        // Plus rien à confirmer -> on retire le bouton ; sinon on le réactive.
        if (0 === attente) {
            bouton.remove();
        } else {
            bouton.disabled = false;
            bouton.innerHTML = label;
        }
    }

    section(jour) {
        return jour ? this.element.querySelector(`section[data-jour="${CSS.escape(jour)}"]`) : null;
    }
}
