import { Controller } from '@hotwired/stimulus';

/**
 * Envoi groupé d'emails de relance (1 par vendeur ou 1 par secrétaire),
 * chacun avec la liste de SES dossiers en correction côté ASP.
 * Confirmation native + feedback inline.
 */
export default class extends Controller {
    static values = { url: String, token: String };
    static targets = ['message'];

    async envoyer(event) {
        const button = event.currentTarget;
        const type = button.dataset.bonusEcoRelanceGroupeeTypeParam;
        const label = type === 'vendeur' ? 'tous les vendeurs' : 'toutes les secrétaires';

        if (!confirm(`Envoyer un email récapitulatif à ${label} avec leurs dossiers en correction ?`)) {
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
                this.setMessage(data.message || 'Relances envoyées.', 'text-positive');
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
        this.messageTarget.classList.remove('text-positive', 'text-negative', 'text-ink/55');
        classes.split(' ').forEach((c) => this.messageTarget.classList.add(c));
    }
}
