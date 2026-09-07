<?php

declare(strict_types=1);

namespace App\Creances\Enum;

/**
 * Etat courant de la relance d'un compte.
 */
enum CompteStrategieEtat: string
{
    case ARelancer = 'a_relancer';
    case EnCours = 'cours';
    case Desactive = 'desactive';
    case Repositionne = 'repositionne';
    case Erreur = 'erreur';
    case Fin = 'fin';

    public function libelle(): string
    {
        return match ($this) {
            self::ARelancer => 'A relancer',
            self::EnCours => 'En cours de traitement',
            self::Desactive => 'Relance desactivee',
            self::Repositionne => 'Relance repositionnee',
            self::Erreur => 'En erreur',
            self::Fin => 'Fin de strategie',
        };
    }
}
