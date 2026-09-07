import { Controller } from '@hotwired/stimulus';

/*
 * Validation IBAN / BIC en direct a la saisie (secretaire), 100 % cote navigateur.
 *
 * IBAN : detecte le pays (2 premieres lettres), verifie la LONGUEUR officielle
 * attendue de ce pays (registre SWIFT) puis la cle de controle (mod-97). Affiche
 * un feedback sous le champ : compte de caracteres restants, longueur invalide,
 * ou IBAN valide. INFORMATIF — ne bloque jamais le depot (une saisie etrangere
 * legitime reste possible).
 *
 * BIC : structure (8 ou 11 caracteres) + coherence du pays avec l'IBAN saisi.
 */

// Longueur TOTALE de l'IBAN par pays (sans espaces). Registre officiel SWIFT.
const LONGUEURS = {
    AD: 24, AE: 23, AL: 28, AT: 20, AZ: 28, BA: 20, BE: 16, BG: 22, BH: 22, BI: 27,
    BR: 29, BY: 28, CH: 21, CR: 22, CY: 28, CZ: 24, DE: 22, DJ: 27, DK: 18, DO: 28,
    EE: 20, EG: 29, ES: 24, FI: 18, FK: 18, FO: 18, FR: 27, GB: 22, GE: 22, GI: 23,
    GL: 18, GR: 27, GT: 28, HN: 28, HR: 21, HU: 28, IE: 22, IL: 23, IQ: 23, IS: 26,
    IT: 27, JO: 30, KW: 30, KZ: 20, LB: 28, LC: 32, LI: 21, LT: 20, LU: 20, LV: 21,
    LY: 25, MC: 27, MD: 24, ME: 22, MK: 19, MN: 20, MR: 27, MT: 31, MU: 30, NI: 28,
    NL: 18, NO: 15, OM: 23, PK: 24, PL: 28, PS: 29, PT: 25, QA: 29, RO: 24, RS: 22,
    RU: 33, SA: 24, SC: 31, SD: 18, SE: 24, SI: 19, SK: 24, SM: 27, SO: 23, ST: 25,
    SV: 28, TL: 23, TN: 24, TR: 26, UA: 29, VA: 22, VG: 24, XK: 20,
};

// Noms FR des pays courants (sinon on affiche le code a deux lettres).
const PAYS = {
    FR: 'France', BE: 'Belgique', DE: 'Allemagne', ES: 'Espagne', IT: 'Italie',
    LU: 'Luxembourg', NL: 'Pays-Bas', PT: 'Portugal', CH: 'Suisse', GB: 'Royaume-Uni',
    MC: 'Monaco', AT: 'Autriche', IE: 'Irlande', PL: 'Pologne', SE: 'Suede',
    DK: 'Danemark', FI: 'Finlande', NO: 'Norvege', AD: 'Andorre', SM: 'Saint-Marin',
};

// Classes de couleur (charte de la suite) selon la gravite du message.
const COULEURS = {
    neutre: 'text-ink/55',
    attention: 'text-gold',
    erreur: 'text-negative',
    ok: 'text-positive',
};

export default class extends Controller {
    static targets = ['iban', 'ibanMsg', 'bic', 'bicMsg'];

    // Declenche par input/change sur les champs IBAN et BIC.
    check() {
        this.verifierIban();
        this.verifierBic();
    }

    verifierIban() {
        if (!this.hasIbanMsgTarget) return;
        const iban = (this.hasIbanTarget ? this.ibanTarget.value : '').replace(/\s+/g, '').toUpperCase();

        if ('' === iban) {
            this.afficher(this.ibanMsgTarget, '', '');
            return;
        }
        if (!/^[A-Z]{2}/.test(iban)) {
            this.afficher(this.ibanMsgTarget, 'attention', 'Commencez par le code pays (ex. FR76…).');
            return;
        }

        const code = iban.slice(0, 2);
        const attendue = LONGUEURS[code];
        const nom = PAYS[code] || code;

        if (undefined === attendue) {
            this.afficher(this.ibanMsgTarget, 'attention', `Code pays « ${code} » inconnu.`);
            return;
        }

        const n = iban.length;
        if (n < attendue) {
            const reste = attendue - n;
            this.afficher(this.ibanMsgTarget, 'neutre', `IBAN ${nom} : ${attendue} caractères attendus, ${n} saisis (${reste} manquant${reste > 1 ? 's' : ''}).`);
            return;
        }
        if (n > attendue) {
            const trop = n - attendue;
            this.afficher(this.ibanMsgTarget, 'erreur', `IBAN ${nom} : ${attendue} caractères attendus, ${n} saisis (${trop} de trop).`);
            return;
        }
        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) {
            this.afficher(this.ibanMsgTarget, 'erreur', 'Format IBAN invalide (attendu : 2 lettres, 2 chiffres, puis le compte).');
            return;
        }
        if (1 !== this.mod97(iban)) {
            this.afficher(this.ibanMsgTarget, 'erreur', `IBAN ${nom} : longueur correcte mais clé de contrôle invalide — vérifiez la saisie.`);
            return;
        }
        this.afficher(this.ibanMsgTarget, 'ok', `IBAN ${nom} valide (${attendue} caractères).`);
    }

    verifierBic() {
        if (!this.hasBicMsgTarget) return;
        const bic = (this.hasBicTarget ? this.bicTarget.value : '').replace(/\s+/g, '').toUpperCase();

        if ('' === bic) {
            this.afficher(this.bicMsgTarget, '', '');
            return;
        }
        if (!/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(bic)) {
            this.afficher(this.bicMsgTarget, 'attention', 'BIC : 8 ou 11 caractères (ex. BNPAFRPPXXX).');
            return;
        }
        const paysBic = bic.slice(4, 6);
        const iban = (this.hasIbanTarget ? this.ibanTarget.value : '').replace(/\s+/g, '').toUpperCase();
        const paysIban = iban.slice(0, 2);
        if ('' !== paysIban && undefined !== LONGUEURS[paysIban] && paysBic !== paysIban) {
            this.afficher(this.bicMsgTarget, 'attention', `Le BIC (${paysBic}) et l'IBAN (${paysIban}) ne sont pas du même pays.`);
            return;
        }
        this.afficher(this.bicMsgTarget, 'ok', 'BIC valide.');
    }

    afficher(cible, niveau, texte) {
        cible.textContent = texte;
        cible.className = `mt-1 text-xs ${COULEURS[niveau] || ''}`;
        cible.classList.toggle('hidden', '' === texte);
    }

    // Cle de controle IBAN : deplace les 4 premiers caracteres a la fin, convertit
    // les lettres (A=10 … Z=35) puis calcule le modulo 97 (valide si === 1).
    mod97(iban) {
        const r = iban.slice(4) + iban.slice(0, 4);
        let total = 0;
        for (const ch of r) {
            const bloc = ch >= 'A' && ch <= 'Z' ? String(ch.charCodeAt(0) - 55) : ch;
            for (const d of bloc) {
                total = (total * 10 + (d.charCodeAt(0) - 48)) % 97;
            }
        }
        return total;
    }
}
