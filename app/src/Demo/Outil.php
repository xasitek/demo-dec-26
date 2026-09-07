<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Les dix outils du memoire, et la chaine qui les relie.
 *
 * Un outil declare : son etape de la chaine, le probleme qu'il traite, les
 * postes pour lesquels il change vraiment quelque chose, et l'ecran ou chacun
 * de ces postes atterrit. Un poste qui verrait exactement le meme ecran qu'un
 * autre n'est PAS propose : la selection de profil n'est pas decorative.
 */
final class Outil
{
    public const ETAPES = [
        'diagnostiquer' => 'Diagnostiquer',
        'fiabiliser' => 'Fiabiliser',
        'piloter' => 'Piloter et sécuriser',
        'agir' => 'Agir',
    ];

    /**
     * @param array<string, string> $vues persona => chemin d'atterrissage
     */
    private function __construct(
        public readonly int $numero,
        public readonly string $cle,
        public readonly string $nom,
        public readonly string $etape,
        public readonly string $probleme,
        public readonly array $vues,
        public readonly bool $disponible,
        public readonly string $indicateur,
    ) {
    }

    /** @return list<self> */
    public static function tous(): array
    {
        $p = Persona::class;

        return [
            new self(1, 'cadrage', 'Cadrer la mission', 'diagnostiquer',
                "Une mission d'optimisation du cycle créances se cadre à l'aveugle : personne ne sait ce qu'elle couvre ni ce qu'elle coûte.",
                [Persona::EXPERT_COMPTABLE->value => '/demo/outil/cadrage/ecran'],
                false, 'cadrage'),

            new self(2, 'cartographie', 'Cartographier le cycle', 'diagnostiquer',
                "Nul ne sait où, de la commande à l'encaissement, la responsabilité change de mains et la créance se perd.",
                [Persona::EXPERT_COMPTABLE->value => '/demo/outil/cartographie/ecran'],
                false, 'cartographie'),

            new self(3, 'maturite', 'Mesurer la maturité', 'diagnostiquer',
                "Le degré de maîtrise du cycle se juge à l'impression, jamais à la mesure : aucun progrès ne se démontre.",
                [Persona::EXPERT_COMPTABLE->value => '/demo/outil/maturite/ecran',
                    Persona::DIRECTEUR_COMPTABLE->value => '/demo/outil/maturite/ecran'],
                false, 'maturite'),

            new self(4, 'affectation', 'Identifier et affecter les règlements', 'fiabiliser',
                'Un virement arrive, son libellé est tronqué par la banque, et personne ne sait quelle créance il règle.',
                [Persona::COMPTABLE->value => '/affectation',
                    Persona::DIRECTEUR_COMPTABLE->value => '/affectation/pilotage',
                    Persona::EXPERT_COMPTABLE->value => '/affectation/methode'],
                true, 'affectation'),

            new self(5, 'lettrage', 'Lettrer les écritures', 'fiabiliser',
                'Un lettrage généraliste échoue sur un compte client automobile : les montants ne se répondent pas et les clés fortes manquent une fois sur deux.',
                [Persona::COMPTABLE->value => '/lettrage',
                    Persona::DIRECTEUR_COMPTABLE->value => '/lettrage/pilotage',
                    Persona::EXPERT_COMPTABLE->value => '/lettrage/methode'],
                true, 'lettrage'),

            new self(6, 'pilotage', 'Piloter le BFR et le DSO', 'piloter',
                "L'encours client se lit de deux façons qui ne donnent pas le même chiffre, et aucune ne descend jusqu'à l'écriture.",
                // Le comptable entre par SA file de dossiers, pas par le
                // consolide du groupe : les indicateurs orientent le travail,
                // ils ne le remplacent pas.
                [Persona::COMPTABLE->value => '/mes-creances',
                    Persona::DIRECTEUR_CONCESSION->value => '/cockpit',
                    Persona::DIRECTEUR_COMPTABLE->value => '/cockpit/multi-sites',
                    Persona::CREDIT_MANAGER->value => '/cockpit/risque',
                    Persona::EXPERT_COMPTABLE->value => '/cockpit/methode'],
                true, 'pilotage'),

            new self(7, 'grands-comptes', 'Sécuriser les dossiers grands comptes', 'piloter',
                "Chaque loueur exige un dossier de pièces différent, et une facture reste impayée pour une seule pièce absente que personne n'a identifiée.",
                // La secretaire depose et complete, le comptable controle.
                // Les vues directeur viennent apres.
                [Persona::SECRETAIRE->value => '/dossiers',
                    Persona::COMPTABLE->value => '/dossiers/controle',
                    Persona::DIRECTEUR_CONCESSION->value => '/dossiers/mon-site',
                    Persona::DIRECTEUR_COMPTABLE->value => '/dossiers/pilotage',
                    Persona::EXPERT_COMPTABLE->value => '/dossiers/methode'],
                true, 'grands-comptes'),

            new self(8, 'remboursements', 'Contrôler les remboursements', 'piloter',
                "Un remboursement client est un décaissement : rien ne garantit qu'il n'est pas un doublon, un surpaiement, ou un virement vers le mauvais compte.",
                [Persona::SECRETAIRE->value => '/remboursement/mes-dossiers',
                    Persona::COMPTABLE->value => '/remboursement',
                    Persona::DIRECTEUR_CONCESSION->value => '/remboursement/suivi?statut=a_valider_directeur',
                    Persona::DIRECTEUR_COMPTABLE->value => '/remboursement/suivi',
                    Persona::EXPERT_COMPTABLE->value => '/remboursement/paiements/journal'],
                true, 'remboursements'),

            new self(9, 'comites', 'Piloter les comités de créances', 'agir',
                "Un comité produit des décisions que personne n'agrège : la même cause revient chaque mois sans jamais être traitée à la racine.",
                [Persona::COMPTABLE->value => '/demo/outil/comites/ecran',
                    Persona::DIRECTEUR_CONCESSION->value => '/demo/outil/comites/ecran',
                    Persona::DIRECTEUR_COMPTABLE->value => '/demo/outil/comites/ecran',
                    Persona::CREDIT_MANAGER->value => '/demo/outil/comites/ecran'],
                false, 'comites'),

            new self(10, 'relance', 'Relancer utilement', 'agir',
                "On relance sur l'ancienneté, donc on relance des factures déjà payées, bloquées pour une pièce, ou dues par un financeur. La relation client paie l'erreur.",
                [Persona::COMPTABLE->value => '/creances',
                    Persona::CREDIT_MANAGER->value => '/creances/strategies',
                    Persona::DIRECTEUR_COMPTABLE->value => '/creances/analyses',
                    Persona::EXPERT_COMPTABLE->value => '/creances/priorites'],
                true, 'relance'),
        ];
    }

    public static function parCle(string $cle): ?self
    {
        foreach (self::tous() as $o) {
            if ($o->cle === $cle) {
                return $o;
            }
        }

        return null;
    }

    /** @return array<string, list<self>> */
    public static function parEtape(): array
    {
        $groupes = array_fill_keys(array_keys(self::ETAPES), []);
        foreach (self::tous() as $o) {
            $groupes[$o->etape][] = $o;
        }

        return $groupes;
    }

    /** @return list<Persona> */
    public function personas(): array
    {
        return array_values(array_filter(
            array_map(static fn (string $v): ?Persona => Persona::tryFrom($v), array_keys($this->vues))
        ));
    }

    public function vuePour(Persona $p): ?string
    {
        return $this->vues[$p->value] ?? null;
    }
}
