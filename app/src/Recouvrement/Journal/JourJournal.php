<?php

declare(strict_types=1);

namespace App\Recouvrement\Journal;

use DateTimeImmutable;

/**
 * Jour consulté dans le "Journal des relances" : borne la journée en [début, fin[
 * (demi-ouvert -> indexable), gère la navigation jour précédent / suivant (jamais
 * dans le futur) et le libellé français.
 *
 * Objet pur : "aujourd'hui" est injecté (pas de new DateTimeImmutable() interne),
 * ce qui le rend testable unitairement.
 */
final class JourJournal
{
    /** @var list<string> */
    private const JOURS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /** @var list<string> */
    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private function __construct(
        private readonly DateTimeImmutable $jour,        // minuit du jour consulté
        private readonly DateTimeImmutable $aujourdhui,  // minuit du jour courant
    ) {
    }

    /**
     * Construit le jour à afficher depuis le paramètre d'URL (format Y-m-d).
     * Valeur absente ou invalide -> aujourd'hui. Jamais dans le futur : on borne
     * à aujourd'hui (on ne relance pas demain).
     */
    public static function depuis(?string $param, DateTimeImmutable $aujourdhui): self
    {
        $today = $aujourdhui->setTime(0, 0, 0);
        $jour = $today;

        if (null !== $param && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $param)) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $param);
            // createFromFormat déborde sur une date invalide ("2026-13-40") : on
            // rejette si le reformatage ne redonne pas l'entrée.
            if ($d instanceof DateTimeImmutable && $d->format('Y-m-d') === $param) {
                $jour = $d > $today ? $today : $d;
            }
        }

        return new self($jour, $today);
    }

    /** Borne basse (minuit du jour), incluse. */
    public function debut(): DateTimeImmutable
    {
        return $this->jour;
    }

    /** Borne haute (minuit du lendemain), exclue. */
    public function fin(): DateTimeImmutable
    {
        return $this->jour->modify('+1 day');
    }

    public function iso(): string
    {
        return $this->jour->format('Y-m-d');
    }

    public function estAujourdhui(): bool
    {
        return $this->jour == $this->aujourdhui;
    }

    public function estHier(): bool
    {
        return $this->jour == $this->aujourdhui->modify('-1 day');
    }

    public function precedentIso(): string
    {
        return $this->jour->modify('-1 day')->format('Y-m-d');
    }

    /** Jour suivant, ou null si on est déjà aujourd'hui (pas de futur). */
    public function suivantIso(): ?string
    {
        return $this->estAujourdhui() ? null : $this->jour->modify('+1 day')->format('Y-m-d');
    }

    /** Libellé français en minuscules, ex. "jeudi 10 juillet 2026". */
    public function libelle(): string
    {
        return sprintf(
            '%s %d %s %d',
            self::JOURS[(int) $this->jour->format('N') - 1],
            (int) $this->jour->format('j'),
            self::MOIS[(int) $this->jour->format('n') - 1],
            (int) $this->jour->format('Y'),
        );
    }
}
