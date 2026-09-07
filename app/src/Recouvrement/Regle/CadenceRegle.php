<?php

declare(strict_types=1);

namespace App\Recouvrement\Regle;

use App\Recouvrement\Entity\RegleRelance;

/**
 * Cadence d'une règle : à partir du retard (jours), calcule le niveau de relance
 * atteignable et si l'on est en mise en demeure. Objet pur (aucune dépendance,
 * aucune date "now") -> testable unitairement.
 *
 * Modèle : 1re relance à `delaiInitial` jours de retard, puis une tous les
 * `intervalle` jours. Mise en demeure dès `seuilMed` jours. Si `continuerApresMed`
 * est faux, la MED est le dernier niveau (on ne monte pas au-delà).
 */
final class CadenceRegle
{
    public function __construct(
        private readonly int $delaiInitial,
        private readonly int $intervalle,
        private readonly int $seuilMed,
        private readonly bool $continuerApresMed,
    ) {
    }

    public static function depuisRegle(RegleRelance $regle): self
    {
        return new self(
            $regle->getDelaiInitial(),
            $regle->getIntervalle(),
            $regle->getSeuilMed(),
            $regle->isContinuerApresMed(),
        );
    }

    /**
     * Niveau atteignable au vu du retard. 0 = pas encore l'heure de relancer
     * (retard < délai initial). Bornée au niveau de MED si l'on n'escalade pas
     * au-delà de la mise en demeure.
     */
    public function niveauPourRetard(int $retard): int
    {
        if ($retard < $this->delaiInitial) {
            return 0;
        }

        $niveau = intdiv($retard - $this->delaiInitial, $this->pas()) + 1;

        if (!$this->continuerApresMed && $retard >= $this->seuilMed) {
            $niveau = min($niveau, $this->niveauMiseEnDemeure());
        }

        return $niveau;
    }

    /**
     * Premier niveau qui correspond à une mise en demeure (retard >= seuilMed).
     */
    public function niveauMiseEnDemeure(): int
    {
        // Jamais de mise en demeure au tout premier contact (démarrage doux) :
        // même mal réglée (seuil <= délai initial), la MED tombe au niveau 2 minimum.
        if ($this->seuilMed <= $this->delaiInitial) {
            return 2;
        }

        return intdiv($this->seuilMed - $this->delaiInitial, $this->pas()) + 1;
    }

    /**
     * Un niveau donné est-il une mise en demeure ?
     */
    public function estMiseEnDemeure(int $niveau): bool
    {
        return $niveau >= $this->niveauMiseEnDemeure();
    }

    /** Intervalle plancher à 1 jour (évite une division par zéro sur une règle mal saisie). */
    private function pas(): int
    {
        return max(1, $this->intervalle);
    }
}
