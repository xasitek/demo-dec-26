import { Controller } from '@hotwired/stimulus';

/*
 * Scroll infini generique et reutilisable.
 *
 * Quand la sentinelle devient visible, charge la tranche suivante
 * (?page=N&fragment=1 + filtres deja dans l'URL) et l'ajoute a la liste.
 *
 * Valeurs : url (base sans page), page (courante), totalPages.
 * Cibles  : items (conteneur), sentinel (declencheur), loading (indicateur).
 */
export default class extends Controller {
    static targets = ['items', 'sentinel', 'loading', 'trigger'];
    static values = { url: String, page: Number, totalPages: Number, manual: Boolean };

    connect() {
        this.loading = false;

        if (this.pageValue >= this.totalPagesValue) {
            this.hideMore();
            return;
        }

        // Mode manuel : un bouton "Voir +" declenche loadMore(), pas de scroll auto.
        if (this.manualValue) {
            return;
        }

        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting) {
                    this.loadMore();
                }
            },
            { rootMargin: '300px' },
        );
        this.observer.observe(this.sentinelTarget);
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    async loadMore() {
        if (this.loading || this.pageValue >= this.totalPagesValue) {
            return;
        }
        this.loading = true;
        this.toggleLoading(true);

        const next = this.pageValue + 1;
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('page', String(next));
        url.searchParams.set('fragment', '1');

        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.ok) {
                const html = await response.text();
                this.itemsTarget.insertAdjacentHTML('beforeend', html);
                this.pageValue = next;
            }
        } catch (error) {
            // Echec silencieux : ne casse pas la page.
        } finally {
            this.loading = false;
            this.toggleLoading(false);
            if (this.pageValue >= this.totalPagesValue) {
                this.hideMore();
            }
        }
    }

    hideMore() {
        this.hideSentinel();
        if (this.hasTriggerTarget) {
            this.triggerTarget.style.display = 'none';
        }
    }

    hideSentinel() {
        if (this.hasSentinelTarget) {
            this.sentinelTarget.style.display = 'none';
        }
    }

    toggleLoading(visible) {
        if (this.hasLoadingTarget) {
            this.loadingTarget.style.display = visible ? '' : 'none';
        }
    }
}
