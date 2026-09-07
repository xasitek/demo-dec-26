<?php

declare(strict_types=1);

namespace App\Shared\Enum;

/**
 * Avancement d'une remontee. La boucle de retour est ce qui fait vivre une boite
 * a idees : chaque passage de statut notifie l'auteur (cf. SuggestionService).
 */
enum StatutSuggestion: string
{
    case NOUVELLE = 'nouvelle';
    case VUE = 'vue';
    case EN_COURS = 'en_cours';
    case FAITE = 'faite';
    case REFUSEE = 'refusee';

    public function libelle(): string
    {
        return match ($this) {
            self::NOUVELLE => 'Nouvelle',
            self::VUE => 'Vue',
            self::EN_COURS => 'En cours',
            self::FAITE => 'Faite',
            // Volontairement pas « Refusée » : le mot compte pour donner envie de
            // proposer a nouveau.
            self::REFUSEE => 'Non retenue',
        };
    }

    /** Classes Tailwind du badge (charte de la suite : navy domine, gold accentue). */
    public function classesBadge(): string
    {
        return match ($this) {
            self::NOUVELLE => 'border-gold/40 bg-gold/10 text-gold-dark',
            self::VUE => 'border-hairline bg-surface text-ink/70',
            self::EN_COURS => 'border-navy/25 bg-navy/5 text-navy',
            self::FAITE => 'border-positive/30 bg-positive/10 text-positive',
            self::REFUSEE => 'border-hairline bg-surface text-ink/50',
        };
    }

    /** Tonalite de la notification envoyee a l'auteur (cf. NotificationService). */
    public function tonaliteNotification(): string
    {
        return match ($this) {
            self::FAITE => 'succes',
            self::REFUSEE => 'alerte',
            default => 'info',
        };
    }

    public function estCloturee(): bool
    {
        return self::FAITE === $this || self::REFUSEE === $this;
    }

    /**
     * L'auteur est-il prevenu de ce changement de statut ? « Vue » ne merite pas
     * une notification : elle ne dit rien de plus que « recu ».
     */
    public function meriteNotification(): bool
    {
        return self::VUE !== $this && self::NOUVELLE !== $this;
    }

    /**
     * @return array<string, string> valeur => libelle (pour les filtres)
     */
    public static function choix(): array
    {
        $choix = [];
        foreach (self::cases() as $case) {
            $choix[$case->value] = $case->libelle();
        }

        return $choix;
    }
}
