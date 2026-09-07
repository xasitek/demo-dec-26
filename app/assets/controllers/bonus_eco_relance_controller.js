import { Controller } from '@hotwired/stimulus';

/**
 * Envoi d'une relance email (vendeur/secretaire) depuis le panel detail.
 * Confirmation native + POST AJAX, feedback inline.
 */
export default class extends Controller {
    static values = { url: String, token: String };
    static targets = ['message'];

    async envoyer(event) {
        const button = event.currentTarget;
        const type = button.dataset.bonusEcoRelanceTypeParam;
        const label = type === 'vendeur' ? 'le vendeur' : 'la secrétaire';

        if (!confirm(`Envoyer une relance par email à ${label} ?`)) {
            return;
        }

        const buttons = this.element.querySelectorAll('button');
        buttons.forEach((b) => { b.disabled = true; });
        this.setMessage('Envoi en cours…', 'text-ink/55');

        try {
            const form = new FormData();
            form.append('_token', this.tokenValue);
            form.append('type', type);

            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: form,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            const data = await response.json();

            if (response.ok && data.ok) {
                this.setMessage('Relance envoyée. Rechargez le panel pour voir l\'historique.', 'text-positive');
            } else {
                this.setMessage(`Échec : ${data.erreur || 'erreur inconnue'}`, 'text-negative');
            }
        } catch (e) {
            this.setMessage(`Erreur réseau : ${e.message}`, 'text-negative');
        } finally {
            buttons.forEach((b) => { b.disabled = false; });
        }
    }

    setMessage(text, classes) {
        if (!this.hasMessageTarget) return;
        this.messageTarget.textContent = text;
        this.messageTarget.classList.remove('hidden');
        // Conserve les classes utilitaires (mt-3, text-xs) et applique le ton.
        this.messageTarget.classList.remove('text-positive', 'text-negative', 'text-ink/55');
        classes.split(' ').forEach((c) => this.messageTarget.classList.add(c));
    }
}
