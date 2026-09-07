import { Controller } from '@hotwired/stimulus';

/**
 * Auto-soumission d'un formulaire avec debounce sur saisie clavier.
 * Usage :
 *   <input data-controller="search-debounce"
 *          data-action="input->search-debounce#submit"
 *          data-search-debounce-delay-value="400">
 *
 * Le formulaire parent est soumis 400ms apres la derniere frappe.
 * Compatible Turbo : le data-turbo-frame du <form> est respecte.
 */
export default class extends Controller {
    static values = {
        delay: { type: Number, default: 350 },
    };

    disconnect() {
        clearTimeout(this.timer);
    }

    submit() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            const form = this.element.form ?? this.element.closest('form');
            if (!form) return;
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }, this.delayValue);
    }
}
