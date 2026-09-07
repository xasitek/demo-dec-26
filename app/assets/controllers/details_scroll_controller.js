import { Controller } from '@hotwired/stimulus';

/*
 * Quand un <details> s'ouvre, fait scroller le panneau parent jusqu'a ce que
 * la section soit visible. Pas d'effet si la section est deja entierement
 * dans le viewport (block: 'nearest').
 */
export default class extends Controller {
    onToggle() {
        if (this.element.open) {
            this.element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}
