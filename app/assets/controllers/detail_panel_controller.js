import { Controller } from '@hotwired/stimulus';

/*
 * Panneau de detail glissant a droite.
 *
 * - Clic sur une ligne (data-action="click->detail-panel#open"
 *   data-detail-panel-id-param="<id>") : ouvre le panneau avec le detail
 *   du dossier, marque la ligne `is-selected`.
 * - Boutons fleche dans le header (action `prev` / `next`) : navigue vers
 *   le dossier precedent / suivant dans la liste, sans fermer le panneau.
 * - Fermeture : croix, ou touche Echap.
 *
 * Le backdrop ne capture plus les clics (transparent + pointer-events:none) :
 * la table reste cliquable derriere, on peut sauter d'une ligne a l'autre.
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'content', 'prev', 'next'];
    static values = { url: String };

    connect() {
        this.currentId = null;
        this.keyHandler = (e) => this.onKey(e);
        document.addEventListener('keydown', this.keyHandler);
    }

    disconnect() {
        document.removeEventListener('keydown', this.keyHandler);
    }

    // Echap ferme ; fleches haut/bas naviguent d'un dossier a l'autre TANT QUE le
    // panneau est ouvert (sans voler les fleches d'un champ de formulaire).
    onKey(e) {
        if ('Escape' === e.key) {
            this.close();
            return;
        }
        if (this.panelTarget.classList.contains('translate-x-full')) return;
        const tag = document.activeElement?.tagName;
        if ('INPUT' === tag || 'TEXTAREA' === tag || 'SELECT' === tag) return;
        if ('ArrowDown' === e.key) {
            e.preventDefault();
            this.next();
        } else if ('ArrowUp' === e.key) {
            e.preventDefault();
            this.prev();
        }
    }

    async open(event) {
        // preventDefault pour bloquer la navigation si l'element est un <a href="...">
        // (cas du lien "voir" sur les DG soeurs dans le panneau).
        event?.preventDefault?.();
        const target = event.currentTarget;
        const id = event.params?.id ?? target?.dataset?.detailPanelIdParam;
        if (!id) return;
        await this.loadId(id, target);
    }

    next() {
        this.navigate(1);
    }

    prev() {
        this.navigate(-1);
    }

    navigate(direction) {
        if (null === this.currentId) return;
        const rows = this.rows();
        const idx = rows.findIndex((r) => r.dataset.detailPanelIdParam === String(this.currentId));
        if (idx < 0) return;
        const target = rows[idx + direction];
        if (!target) return;
        this.loadId(target.dataset.detailPanelIdParam, target);
    }

    rows() {
        return Array.from(document.querySelectorAll('[data-detail-panel-id-param]'));
    }

    async loadId(id, rowElement) {
        if (!id) return;

        this.markSelected(rowElement, id);
        this.contentTarget.innerHTML = '<p class="text-sm text-ink/55">Chargement...</p>';
        this.show();

        try {
            const url = this.urlValue.replace('__ID__', String(id));
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.ok) {
                this.contentTarget.innerHTML = await response.text();
                this.currentId = id;
                this.updateNavButtons();
                if (rowElement && rowElement.scrollIntoView) {
                    rowElement.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            } else {
                this.contentTarget.innerHTML = '<p class="text-sm text-negative">Erreur de chargement.</p>';
            }
        } catch (error) {
            this.contentTarget.innerHTML = '<p class="text-sm text-negative">Erreur reseau.</p>';
        }
    }

    markSelected(rowElement, id) {
        // Retire `is-selected` de toutes les lignes, l'ajoute sur la nouvelle.
        document.querySelectorAll('[data-detail-panel-id-param].is-selected').forEach((el) => {
            el.classList.remove('is-selected');
        });
        const target = rowElement
            ?? document.querySelector(`[data-detail-panel-id-param="${CSS.escape(String(id))}"]`);
        if (target) target.classList.add('is-selected');
    }

    updateNavButtons() {
        const rows = this.rows();
        const idx = rows.findIndex((r) => r.dataset.detailPanelIdParam === String(this.currentId));
        const hasPrev = idx > 0;
        const hasNext = idx >= 0 && idx < rows.length - 1;
        if (this.hasPrevTarget) this.prevTarget.disabled = !hasPrev;
        if (this.hasNextTarget) this.nextTarget.disabled = !hasNext;
    }

    close() {
        if (this.panelTarget.classList.contains('translate-x-full')) return;
        this.panelTarget.classList.add('translate-x-full');
        document.body.style.overflow = '';
        // Fermer aussi le panneau "factures du client" s'il est ouvert (il est
        // accroche au detail) : evenement ecoute par le controleur factures-panel.
        this.dispatch('close');
        // On garde la ligne `is-selected` pour rappeler quel dossier on a regarde
        // en dernier (utile si on rouvre). Si tu veux que ce soit nettoye a la
        // fermeture, decommente :
        // document.querySelectorAll('[data-detail-panel-id-param].is-selected').forEach(el => el.classList.remove('is-selected'));
    }

    show() {
        this.panelTarget.classList.remove('translate-x-full');
        // pas de bg-lock cote body : la page reste utilisable derriere le panneau
    }

    // Clic sur l'icone rouge "Clôturer" du header : ouvre la modale de cloture en
    // lui passant l'id du retour courant + la cle de conversation (pour retirer la
    // bonne ligne apres cloture). La modale (controleur "cloture") ecoute l'evenement.
    cloturer() {
        if (null === this.currentId) return;
        const row = document.querySelector(`[data-detail-panel-id-param="${CSS.escape(String(this.currentId))}"]`);
        const cle = row ? (row.dataset.conversationCle ?? '') : '';
        document.dispatchEvent(new CustomEvent('recouvrement:ouvrir-cloture', {
            detail: { id: this.currentId, cle },
        }));
    }
}
