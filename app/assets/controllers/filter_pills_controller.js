import { Controller } from '@hotwired/stimulus';

/*
 * Retrait d'un filtre via la pill (X) : on decoche l'input correspondant
 * dans le formulaire ET on relance le submit pour que Turbo mette a jour le
 * frame. Sans ce decochage, l'etat du dropdown resterait visuellement coche
 * alors que l'URL ne contient plus le filtre.
 */
export default class extends Controller {
    remove(event) {
        event.preventDefault();
        const name = event.params.name;
        const value = event.params.value;
        if (!name) return;

        // Multi-select : input[name="cle[]"][value="..."]
        const checkbox = document.querySelector(
            `input[type="checkbox"][name="${CSS.escape(name)}[]"][value="${CSS.escape(value)}"]`,
        );
        if (checkbox) {
            checkbox.checked = false;
            // Notifie le controller multi-select pour rafraichir son label.
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            const form = checkbox.closest('form');
            if (form) form.requestSubmit();
            return;
        }

        // Single select : select[name="cle"]
        const native = document.querySelector(`select[name="${CSS.escape(name)}"]`);
        if (native) {
            native.value = '';
            native.dispatchEvent(new Event('change', { bubbles: true }));
            // custom-select#submit s'occupera de form.requestSubmit().
            return;
        }

        // Fallback : si rien trouve cote form, on suit le lien classique.
        const url = event.params.url;
        if (url) window.location.href = url;
    }
}
