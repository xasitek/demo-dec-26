import { Controller } from '@hotwired/stimulus';

/*
 * Canal temps reel mutualise : UNE seule connexion Mercure par onglet, partagee
 * entre le badge de notifications (demandes d'acces) et la presence (avatars).
 * Gere aussi la bordure de statut de MON avatar (actif / inactif), en local.
 */
const ORDRE_ROLES = [
    'ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_MANAGER',
    'ROLE_AUDITEUR', 'ROLE_COMPTABLE', 'ROLE_SECRETAIRE',
];
const ORDRE_STATUT = { online: 0, idle: 1, offline: 2 };
const STATUT_LABEL = { online: 'en ligne', idle: 'inactif', offline: 'hors ligne' };
const ACTIVITE_EVENTS = ['mousemove', 'keydown', 'scroll', 'click'];
const SEUIL_ACTIVITE_MS = 300000;

export default class extends Controller {
    static targets = ['badge', 'avatars', 'self', 'notifBadge', 'notifList', 'notifEmpty'];
    static values = {
        mercureUrl: String,
        track: Boolean,
        presence: Boolean,
        selfId: Number,
        pingUrl: String,
        listUrl: String,
        disconnectUrl: String,
        notifLuesUrl: String,
        notifLuesCsrf: String,
        notifIcon: String,
    };

    connect() {
        this.presenceMap = new Map();
        this.lastActivity = Date.now();
        this.markActive = () => { this.lastActivity = Date.now(); this.updateSelf(); };
        this.onVisibility = () => this.handleVisibility();
        this.onUnload = () => this.beacon();

        // Notifications navigateur (bureau) : on demande l'autorisation une fois (best-effort).
        this.demanderAutorisationBureau();

        // Le flux Mercure reste OUVERT en permanence (meme onglet en arriere-plan) :
        // les notifications doivent arriver quand l'utilisateur regarde ailleurs.
        this.openStream();
        ACTIVITE_EVENTS.forEach((e) => document.addEventListener(e, this.markActive, { passive: true }));
        this.startTimers();

        document.addEventListener('visibilitychange', this.onVisibility);
        window.addEventListener('pagehide', this.onUnload);
    }

    disconnect() {
        this.closeStream();
        this.stopTimers();
        ACTIVITE_EVENTS.forEach((e) => document.removeEventListener(e, this.markActive));
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('pagehide', this.onUnload);
    }

    // Timers de presence / statut : suspendus en arriere-plan (economie), mais le
    // flux Mercure, lui, ne se coupe jamais -> les notifs arrivent en continu.
    startTimers() {
        this.updateSelf();
        this.selfTimer = setInterval(() => this.updateSelf(), 30000);

        if (this.presenceValue) {
            this.loadPresence();
            this.refreshTimer = setInterval(() => this.loadPresence(), 30000);
        }

        if (this.trackValue) {
            this.ping();
            this.pingTimer = setInterval(() => this.ping(), 20000);
        }
    }

    stopTimers() {
        clearInterval(this.refreshTimer);
        clearInterval(this.pingTimer);
        clearInterval(this.selfTimer);
    }

    handleVisibility() {
        if (document.hidden) {
            // On garde le flux Mercure, on ne coupe que les timers.
            this.stopTimers();
        } else {
            // Retour au premier plan : le flux a pu tomber (mise en veille) -> on
            // s'assure qu'il est ouvert, et on relance les timers de presence.
            this.openStream();
            this.startTimers();
        }
    }

    // ---- Mercure ----

    openStream() {
        if (!this.mercureUrlValue || this.source) {
            return;
        }
        // withCredentials : envoie le cookie "mercureAuthorization" au hub (cross-origin
        // app -> hub) pour autoriser l'abonnement aux topics prives (notifications).
        this.source = new EventSource(this.mercureUrlValue, { withCredentials: true });
        this.source.onmessage = (event) => this.onMessage(event);
    }

    closeStream() {
        if (this.source) {
            this.source.close();
            this.source = null;
        }
    }

    onMessage(event) {
        let data;
        try {
            data = JSON.parse(event.data);
        } catch (e) {
            return;
        }

        if ('demande_acces' === data.type) {
            this.updateBadge(data.count);
        } else if ('retour' === data.type) {
            // Rediffuse a la page (ex. liste des retours) sans ouvrir un 2e flux
            // Mercure : une seule connexion par onglet (cf. docs/REALTIME.md).
            document.dispatchEvent(new CustomEvent('recouvrement:retour', { detail: data }));
        } else if ('facture_sans_pdf' === data.type) {
            // Page "factures sans PDF" : retrait a l'upload / rechargement apres ETL.
            document.dispatchEvent(new CustomEvent('recouvrement:facture-sans-pdf', { detail: data }));
        } else if ('remb_dossier' === data.type) {
            // "Mes dossiers" (secretaire) : badge d'etat mis a jour en direct.
            document.dispatchEvent(new CustomEvent('remboursement:dossier', { detail: data }));
        } else if ('remb_a_verifier' === data.type) {
            // Atelier comptable : un dossier arrive dans la file "a verifier" (insertion live).
            document.dispatchEvent(new CustomEvent('remboursement:a-verifier', { detail: data }));
        } else if ('remb_statut' === data.type) {
            // Changement de statut : maj du badge de la ligne dans les listes /remboursement.
            document.dispatchEvent(new CustomEvent('remboursement:statut', { detail: data }));
        } else if ('preparation' === data.type) {
            // Barre de progression globale (lancement manuel d'une strategie).
            document.dispatchEvent(new CustomEvent('recouvrement:preparation', { detail: data }));
        } else if ('suggestion' === data.type) {
            // Mur des idees : nouvelle idee, vote ou changement de statut.
            document.dispatchEvent(new CustomEvent('suggestions:maj', { detail: data }));
        } else if ('notification' === data.type) {
            this.onNotification(data);
        } else if (undefined !== data.id && undefined !== data.status) {
            if (data.id === this.selfIdValue) {
                return; // on ne s'affiche pas soi-meme dans la pile
            }
            this.presenceMap.set(data.id, data);
            this.renderPresence();
        }
    }

    // ---- Notifications (badge demandes d'acces admin) ----

    updateBadge(count) {
        if (this.hasBadgeTarget && 'number' === typeof count) {
            this.badgeTarget.textContent = count;
            this.badgeTarget.style.display = count > 0 ? '' : 'none';
        }
    }

    // ---- Centre de notifications (cloche, par utilisateur) ----

    onNotification(data) {
        // Notification navigateur (bureau) : visible meme si l'onglet est en arriere-plan.
        this.notifierBureau(data);

        if (this.hasNotifBadgeTarget && 'number' === typeof data.nonLues) {
            this.notifBadgeTarget.textContent = data.nonLues;
            this.notifBadgeTarget.style.display = data.nonLues > 0 ? '' : 'none';
        }
        if (this.hasNotifEmptyTarget) {
            this.notifEmptyTarget.style.display = 'none';
        }
        if (this.hasNotifListTarget && undefined !== data.id) {
            const message = data.message
                ? `<span class="mt-0.5 block text-xs leading-snug text-ink line-clamp-2">${this.escape(data.message)}</span>`
                : '';
            const item = `<a href="${this.escape(`/notifications/${data.id}/ouvrir`)}" class="flex gap-3 border-b border-hairline px-5 py-3.5 transition hover:bg-surface bg-navy/5">`
                + '<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-gold"></span>'
                + `<span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-navy">${this.escape(data.titre)}</span>${message}`
                + '<span class="mt-1 block text-[0.65rem] text-ink/45">à l\'instant</span></span></a>';
            this.notifListTarget.insertAdjacentHTML('afterbegin', item);
        }
    }

    // Ouverture de la cloche : marque TOUT lu, sans clic sur chaque notification.
    // Le badge tombe a zero et la surbrillance "non lu" disparait immediatement ;
    // les notifications restent listees comme historique (juste marquees lues).
    marquerNotifsLues() {
        // Rien a marquer si le badge est deja a zero (evite un POST inutile a chaque ouverture).
        const count = this.hasNotifBadgeTarget ? (parseInt(this.notifBadgeTarget.textContent, 10) || 0) : 0;
        if (count <= 0) {
            return;
        }

        // 1. UI immediate : badge masque + retrait de la pastille et du fond "non lu".
        this.notifBadgeTarget.textContent = '0';
        this.notifBadgeTarget.style.display = 'none';
        if (this.hasNotifListTarget) {
            this.notifListTarget.querySelectorAll('a').forEach((a) => a.classList.remove('bg-navy/5'));
            this.notifListTarget.querySelectorAll('a > span:first-child').forEach((dot) => {
                dot.classList.remove('bg-gold');
                dot.classList.add('bg-transparent');
            });
        }

        // 2. Persistance serveur (best-effort : un echec sera corrige au prochain chargement).
        if (!this.hasNotifLuesUrlValue) {
            return;
        }
        const body = new FormData();
        body.append('_token', this.notifLuesCsrfValue);
        fetch(this.notifLuesUrlValue, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body,
        }).catch(() => {});
    }

    // ---- Notifications navigateur (bureau) ----

    demanderAutorisationBureau() {
        if ('Notification' in window && 'default' === Notification.permission) {
            try {
                Notification.requestPermission().catch(() => {});
            } catch (e) {
                // Safari ancien : API a callback -> ignore
            }
        }
    }

    notifierBureau(data) {
        if (!('Notification' in window) || 'granted' !== Notification.permission) {
            return;
        }
        try {
            const notif = new Notification(data.titre || 'Finance Créances', {
                body: data.message || '',
                icon: this.hasNotifIconValue ? this.notifIconValue : undefined,
                tag: undefined !== data.id ? `fc-notif-${data.id}` : undefined,
            });
            if (data.url) {
                notif.onclick = () => {
                    window.focus();
                    window.location.href = data.url;
                };
            }
        } catch (e) {
            // silencieux : la cloche in-app reste la source de verite
        }
    }

    // ---- Mon statut (bordure de mon avatar) ----

    updateSelf() {
        if (!this.hasSelfTarget) {
            return;
        }
        const online = (Date.now() - this.lastActivity) < SEUIL_ACTIVITE_MS;
        this.selfTarget.classList.toggle('is-online', online);
        this.selfTarget.classList.toggle('is-idle', !online);
    }

    // ---- Presence ----

    ping() {
        const active = (Date.now() - this.lastActivity) < 60000;
        fetch(this.pingUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ active }),
        }).catch(() => {});
    }

    async loadPresence() {
        if (!this.presenceValue) {
            return;
        }
        try {
            const response = await fetch(this.listUrlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.ok) {
                const list = await response.json();
                this.presenceMap = new Map(list.map((p) => [p.id, p]));
                this.renderPresence();
            }
        } catch (e) {
            // silencieux
        }
    }

    renderPresence() {
        if (!this.hasAvatarsTarget) {
            return;
        }
        const items = [...this.presenceMap.values()].sort((a, b) => {
            const r = ORDRE_ROLES.indexOf(a.roleKey) - ORDRE_ROLES.indexOf(b.roleKey);
            if (0 !== r) return r;
            const s = (ORDRE_STATUT[a.status] ?? 3) - (ORDRE_STATUT[b.status] ?? 3);
            return 0 !== s ? s : a.name.localeCompare(b.name);
        });

        this.avatarsTarget.innerHTML = items.map((p) => this.avatarHtml(p)).join('');
    }

    avatarHtml(p) {
        const label = this.escape(`${p.name} · ${STATUT_LABEL[p.status] ?? p.status}`);
        const inner = p.avatar
            ? `<img src="${this.escape(p.avatar)}" alt="" referrerpolicy="no-referrer">`
            : `<span class="presence-initials">${this.escape(p.initials)}</span>`;
        return `<span class="presence-avatar" data-label="${label}">${inner}<span class="presence-dot ${this.escape(p.status)}"></span></span>`;
    }

    escape(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    beacon() {
        if (this.trackValue && this.disconnectUrlValue && navigator.sendBeacon) {
            navigator.sendBeacon(this.disconnectUrlValue);
        }
    }
}
