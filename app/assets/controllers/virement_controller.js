import { Controller } from '@hotwired/stimulus';

/*
 * Écran « Virements SEPA » (manager).
 *  - Onglets « À télécharger » / « Déjà téléchargés » : un seul panneau visible à la
 *    fois. L'onglet actif est mémorisé dans l'URL (?onglet=) pour survivre à la
 *    recherche/au filtre.
 *  - apresTelechargement : après « Tout télécharger » (ZIP), le serveur marque le lot ;
 *    on recharge la page peu après pour refléter le passage en « Déjà téléchargés ».
 *  - voirDoublon : clic sur un badge « Doublon » -> surligne toutes les lignes du même
 *    bénéficiaire (même clé anti-doublon) et scrolle vers le jumeau. Re-clic = enlève.
 */
export default class extends Controller {
    static targets = ['panneau', 'onglet', 'apercu', 'detail', 'detailContenu', 'fiche', 'ficheContenu'];
    static values = { panneauUrl: String, zipUrl: String };

    connect() {
        const params = new URLSearchParams(window.location.search);
        this.activer(params.get('onglet') || 'atraiter');
        this.gestionEchap = (e) => { if ('Escape' === e.key) this.fermerTout(); };
        document.addEventListener('keydown', this.gestionEchap);
    }

    disconnect() {
        document.removeEventListener('keydown', this.gestionEchap);
    }

    fermerTout() {
        this.fermerFiche();
        this.fermerDetail();
        this.fermerApercu();
    }

    // Panneau glissant à droite : liste des dossiers en anomalie / doublon.
    ouvrirApercu() {
        if (this.hasApercuTarget) this.apercuTarget.classList.remove('translate-x-full');
    }

    fermerApercu() {
        if (this.hasApercuTarget) this.apercuTarget.classList.add('translate-x-full');
    }

    // Clic sur une ligne en alerte (doublon / IBAN ou montant modifié) -> ouvre le panneau de
    // DROITE avec la fiche du dossier (infos + pièces + historique), chargée à la volée. On
    // ignore les clics sur la case à cocher / un bouton / un lien.
    ouvrirDetail(event) {
        if (event.target.closest('input, button, a, label')) return;
        this.chargerPanneau(event.currentTarget.dataset.dossierId);
    }

    async chargerPanneau(id) {
        if (!id || !this.hasDetailTarget || !this.hasDetailContenuTarget || !this.panneauUrlValue) return;

        this.detailContenuTarget.innerHTML = '<p class="px-5 py-10 text-center text-sm text-ink/55">Chargement…</p>';
        this.detailTarget.classList.remove('translate-x-full');

        try {
            const url = this.panneauUrlValue.replace('__ID__', String(id));
            const reponse = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            this.detailContenuTarget.innerHTML = reponse.ok
                ? await reponse.text()
                : '<p class="px-5 py-10 text-center text-sm text-negative">Erreur de chargement.</p>';
        } catch (e) {
            this.detailContenuTarget.innerHTML = '<p class="px-5 py-10 text-center text-sm text-negative">Erreur réseau.</p>';
        }
    }

    fermerDetail() {
        if (this.hasDetailTarget) this.detailTarget.classList.add('translate-x-full');
    }

    // Clic sur une référence -> modal central avec la fiche complète du dossier
    // (infos, statut, pièces avec boutons « voir », historique). Fragment partagé
    // charge via la route app_remboursement_dossier_panneau.
    ouvrirFiche(event) {
        this.chargerFiche(event.currentTarget.dataset.id);
    }

    async chargerFiche(id) {
        if (!id || !this.hasFicheTarget || !this.panneauUrlValue) return;

        this.ficheContenuTarget.innerHTML = '<p class="px-5 py-10 text-center text-sm text-ink/55">Chargement…</p>';
        this.ficheTarget.classList.remove('hidden');
        this.ficheTarget.classList.add('flex');

        try {
            const url = this.panneauUrlValue.replace('__ID__', String(id));
            const reponse = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
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

    changerOnglet(event) {
        this.activer(event.currentTarget.dataset.onglet);
    }

    activer(nom) {
        this.panneauTargets.forEach((p) => p.classList.toggle('hidden', p.dataset.onglet !== nom));
        this.ongletTargets.forEach((o) => {
            const actif = o.dataset.onglet === nom;
            o.classList.toggle('bg-navy', actif);
            o.classList.toggle('text-white', actif);
            o.classList.toggle('shadow-sm', actif);
            o.classList.toggle('text-ink/60', !actif);
        });
    }

    // Case d'en-tête : coche / décoche toutes les lignes de la file « À télécharger ».
    toutCocher(event) {
        const coche = event.currentTarget.checked;
        const table = event.currentTarget.closest('table');
        if (table) {
            table.querySelectorAll('input[name="ids[]"]').forEach((cb) => { cb.checked = coche; });
        }
    }

    // Télécharge la sélection en UNE seule archive (sous-dossiers SEPA/ et OD/) : un seul
    // download evite le blocage navigateur du 2e telechargement. Le serveur marque le lot
    // + e-mail directeur. Sans JS, le bouton retombe sur un POST classique (meme archive).
    async telecharger(event) {
        event.preventDefault();
        // Anti double-clic : une seule préparation à la fois.
        if (this.enCours) return;
        const form = event.currentTarget.closest('form');
        if (!form) return;
        const ids = [...form.querySelectorAll('input[name="ids[]"]:checked')].map((i) => i.value);
        if (0 === ids.length) return;
        const token = form.querySelector('input[name="_token"]')?.value ?? '';

        this.enCours = true;
        const bouton = event.currentTarget;
        bouton.disabled = true;
        bouton.classList.add('cursor-not-allowed', 'opacity-80');
        bouton.innerHTML = '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Préparation du téléchargement…';

        try {
            await this.telechargerZip(this.zipUrlValue, ids, token, 'paiements.zip');
        } catch (e) {
            // silencieux : on recharge de toute façon pour refléter l'état réel.
        }
        // Le bouton reste désactivé + spinner jusqu'au rechargement (reflète l'état à jour).
        this.apresTelechargement();
    }

    async telechargerZip(url, ids, token, nomDefaut) {
        if (!url) return;
        const body = new FormData();
        body.append('_token', token);
        ids.forEach((id) => body.append('ids[]', id));
        try {
            const reponse = await fetch(url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!reponse.ok) return;
            // Le serveur renvoie une redirection HTML si aucun fichier : on ne télécharge que du zip.
            if (!(reponse.headers.get('Content-Type') || '').includes('zip')) return;
            const blob = await reponse.blob();
            if (0 === blob.size) return;
            const cd = reponse.headers.get('Content-Disposition') || '';
            const trouve = cd.match(/filename="?([^"]+)"?/);
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = trouve ? trouve[1] : nomDefaut;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(a.href);
        } catch (e) {
            // silencieux
        }
    }

    apresTelechargement() {
        setTimeout(() => window.location.reload(), 1200);
    }

    voirDoublon(event) {
        const cle = event.currentTarget.dataset.cle;
        if (!cle) return;

        const lignes = Array.from(document.querySelectorAll(`tr[data-cle="${CSS.escape(cle)}"]`));
        const dejaActif = lignes.length > 0 && '1' === lignes[0].dataset.surligne;

        document.querySelectorAll('tr[data-surligne="1"]').forEach((l) => {
            l.style.backgroundColor = '';
            l.dataset.surligne = '0';
        });
        if (dejaActif) return;

        lignes.forEach((l) => {
            l.style.backgroundColor = '#fde68a';
            l.dataset.surligne = '1';
        });

        const courant = event.currentTarget.closest('tr');
        const jumeau = lignes.find((l) => l !== courant) ?? lignes[0];
        if (jumeau) jumeau.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
