<?php

declare(strict_types=1);

namespace App\Shared\Secretaire;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un module qui offre un espace au poste secretaire implemente cette interface.
 *
 * C'est le seul point de contact : le module declare son espace, et ni la barre
 * laterale ni le confinement n'ont besoin de le connaitre. Ajouter un module au
 * poste secretaire ne demande donc de toucher aucun autre module.
 */
#[AutoconfigureTag('app.espace_secretaire')]
interface FournisseurEspaceInterface
{
    public function espace(): EspaceSecretaire;
}
