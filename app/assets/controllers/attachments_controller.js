import { Controller } from '@hotwired/stimulus'

/*
 * Pieces jointes de la reponse : ajouter / retirer plusieurs fichiers.
 * Chaque fichier choisi devient un <input type="file" name="pieces[]"> cache
 * dans le formulaire, accompagne d'une puce avec un bouton de suppression.
 */
export default class extends Controller {
    static targets = ['list', 'inputs']

    add() {
        const input = document.createElement('input')
        input.type = 'file'
        input.name = 'pieces[]'
        input.classList.add('hidden')
        input.addEventListener('change', () => this.onChange(input))
        this.inputsTarget.appendChild(input)
        input.click()
    }

    onChange(input) {
        if (!input.files || input.files.length === 0) {
            input.remove()
            return
        }
        this.addChip(input, input.files[0].name)
    }

    addChip(input, name) {
        const chip = document.createElement('span')
        chip.className = 'inline-flex items-center gap-1.5 rounded-md border border-hairline bg-surface px-2.5 py-1 text-xs text-ink/80'

        const icon = document.createElement('span')
        icon.innerHTML = '<svg viewBox="0 0 24 24" class="h-3.5 w-3.5 text-ink/45" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'

        const label = document.createElement('span')
        label.textContent = name
        label.className = 'max-w-[12rem] truncate'

        const remove = document.createElement('button')
        remove.type = 'button'
        remove.setAttribute('aria-label', 'Retirer cette pièce jointe')
        remove.className = 'ml-0.5 text-ink/40 transition hover:text-negative'
        remove.innerHTML = '<svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>'
        remove.addEventListener('click', () => {
            input.remove()
            chip.remove()
        })

        chip.appendChild(icon)
        chip.appendChild(label)
        chip.appendChild(remove)
        this.listTarget.appendChild(chip)
    }
}
