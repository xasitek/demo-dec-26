<?php

declare(strict_types=1);

namespace App\Pilotage\Moteur;

use App\Shared\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le perimetre de l'utilisateur connecte.
 *
 * Un directeur de concession ne voit PAS la consolidation du groupe, et ce
 * n'est pas une commodite d'affichage : c'est la regle. Le cockpit filtre donc
 * a la source, dans les requetes, et non a l'affichage -- un total de groupe
 * calcule puis masque resterait un total de groupe transmis au navigateur.
 *
 * En production, le rattachement vient du referentiel des contacts
 * d'etablissement. La demonstration lit la meme table.
 */
final class Perimetre
{
    /** Le perimetre a-t-il ete resolu a defaut de rattachement declare ? */
    private bool $resolutionParDefaut = false;

    public function __construct(
        private readonly Connection $cnx,
        private readonly Security $securite,
    ) {
    }

    /**
     * Comment le perimetre a ete resolu.
     *
     * L'ecran doit pouvoir le dire : un perimetre deduit n'est pas un
     * perimetre declare, et le confondre serait tromper le lecteur.
     */
    public function resoluParDefaut(): bool
    {
        $this->etablissement();

        return $this->resolutionParDefaut;
    }

    /** L'utilisateur voit-il l'ensemble du groupe ? */
    public function voitLeGroupe(): bool
    {
        return !$this->securite->isGranted('ROLE_DIRECTEUR')
            || $this->securite->isGranted('ROLE_MANAGER')
            || $this->securite->isGranted('ROLE_ADMIN');
    }

    /**
     * Le seul etablissement d'un directeur de concession, ou rien s'il voit tout.
     *
     * Le rattachement passe par le referentiel des contacts d'etablissement,
     * comme en production. A defaut de rattachement declare, on retient le
     * premier etablissement par ordre de code : un perimetre vide afficherait
     * un cockpit vide, ce qui ne demontre rien, et un perimetre implicite qui
     * serait le groupe serait une fuite.
     */
    public function etablissement(): ?string
    {
        if ($this->voitLeGroupe()) {
            return null;
        }

        $utilisateur = $this->securite->getUser();
        $code = null;
        if ($utilisateur instanceof User) {
            $code = $this->cnx->fetchOne(
                'SELECT etablissement_code FROM shared.etablissement_contact
                  WHERE email = ? AND actif ORDER BY etablissement_code LIMIT 1',
                [$utilisateur->getUserIdentifier()]);
        }
        // Le referentiel des contacts porte les codes d'etablissement de la
        // production. Le monde synthetique a ses propres identifiants : un code
        // qui n'y correspond a rien donnerait un cockpit vide, ce qui ne
        // demontre rien. On retient alors l'etablissement qui porte le plus
        // d'encours -- choix deterministe, et l'ecran dit comment il a ete fait.
        // `fetchOne` rend FALSE quand aucune ligne ne repond, pas null. Tester
        // `null !== ...` faisait donc passer tout code pour connu, et le
        // perimetre restait sur un identifiant absent du monde synthetique --
        // le cockpit s'affichait vide sans le dire.
        $connu = \is_string($code) && '' !== $code
            && \is_string($this->cnx->fetchOne(
                'SELECT id FROM affectation.etablissement WHERE id = ?', [$code]));

        if (!$connu) {
            $this->resolutionParDefaut = true;
            $code = $this->cnx->fetchOne(
                "SELECT f.etablissement_id FROM affectation.facture f
                  WHERE f.statut <> 'soldee' AND f.etablissement_id IS NOT NULL
                  GROUP BY 1 ORDER BY sum(f.montant) DESC LIMIT 1");
        }

        return \is_string($code) && '' !== $code ? $code : null;
    }

    /**
     * Les filtres imposes par le perimetre, a fusionner avec ceux de l'ecran.
     *
     * @param array<string, string> $demandes
     *
     * @return array<string, string>
     */
    public function appliquer(array $demandes): array
    {
        $etab = $this->etablissement();
        if (null === $etab) {
            return $demandes;
        }

        // Le perimetre PRIME sur la demande. Un directeur de concession qui
        // changerait l'identifiant dans l'adresse ne verrait pas un autre site.
        $demandes['etablissement_id'] = $etab;

        return $demandes;
    }
}
