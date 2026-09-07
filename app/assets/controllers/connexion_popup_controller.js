import { Controller } from '@hotwired/stimulus'

/*
 * Ouvre la connexion Google en POPUP pour un lien protégé (ex. « Mes dossiers »
 * quand la secrétaire n'est pas encore connectée), puis navigue vers la cible une
 * fois la connexion établie. Repli plein écran si le popup est bloqué.
 */
export default class extends Controller {
    static values = { url: String, cible: String, fallback: String }

    ouvrir(event) {
        event.preventDefault()
        const popup = window.open(this.urlValue, 'oauth-google', 'popup,width=500,height=650')
        if (!popup) { window.location.href = this.fallbackValue || this.urlValue; return }

        let fait = false
        const aller = () => {
            if (fait) return
            fait = true
            clearInterval(timer)
            window.removeEventListener('message', onMsg)
            if (canal) { try { canal.close() } catch (e) { /* noop */ } }
            window.location.href = this.cibleValue
        }
        // Signal principal : BroadcastChannel (same-origin, survit a la coupure
        // popup<->opener que les navigateurs imposent quand le popup a transite par
        // Google, cf. COOP). C'est ce qui reglait le "page blanche + reload".
        let canal = null
        try {
            canal = new BroadcastChannel('remb-auth')
            canal.onmessage = () => aller()
        } catch (e) { canal = null }
        // Reculs : postMessage direct du popup, ou detection de sa fermeture.
        const onMsg = (e) => {
            if (e.origin === window.location.origin && e.data && e.data.type === 'remb-auth-ok') aller()
        }
        window.addEventListener('message', onMsg)
        const timer = setInterval(() => { try { if (popup.closed) aller() } catch (e) { /* COOP */ } }, 800)
    }
}
