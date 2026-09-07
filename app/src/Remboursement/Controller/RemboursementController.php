<?php

declare(strict_types=1);

namespace App\Remboursement\Controller;

use App\Remboursement\Command\RegistreControlesCommand;
use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Enum\DossierStatut;
use App\Remboursement\Enum\StatutSecretaire;
use App\Remboursement\Repository\DossierRepository;
use App\Remboursement\Service\AnalyseVirement;
use App\Shared\Repository\EtablissementContactRepository;
use App\Shared\Repository\EtablissementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Poste COMPTABLE du module Remboursement. Vue "action d'abord" : une section "A
 * verifier" mise EN AVANT (file de travail), puis "Tous les dossiers" (tableau
 * recherchable/filtrable, scroll infini). Un clic sur une ligne ouvre la page detail.
 *
 * Acces reserve au poste comptable et a l'encadrement finance (comptable, directeur,
 * manager rattaches au module) ; la secretaire a son propre espace (Deposer / Mes
 * dossiers) avec controle de propriete. Le prefixe /remboursement est deja garde par
 * MODULE_REMBOURSEMENT (security.yaml) ; ici on restreint en plus par role.
 */
#[Route('/remboursement')]
#[IsGranted(new Expression("is_granted('ROLE_COMPTABLE') or is_granted('ROLE_DIRECTEUR') or is_granted('ROLE_MANAGER')"))]
final class RemboursementController extends AbstractController
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly EtablissementRepository $etablissements,
        private readonly EtablissementContactRepository $contacts,
        private readonly AnalyseVirement $analyse,
    ) {
    }

    /**
     * Onglet "A verifier" : la file de travail du comptable (du plus ancien au plus
     * recent). Insertion + statut en temps reel. Message fun quand il n'y a plus rien.
     */
    #[Route('', name: 'app_remboursement_accueil', methods: ['GET'])]
    public function accueil(): Response
    {
        $aVerifier = $this->dossiers->parStatuts(StatutSecretaire::VERIFICATION->statuts(), 100);
        $etabs = $this->mapEtablissements();
        $groupes = $this->analyse->groupes($this->dossiers->nonRefuses(), $etabs);

        return $this->render('remboursement/a_verifier.html.twig', [
            'aVerifier' => $aVerifier,
            'nbAVerifier' => $this->nbAVerifier(),
            'etabs' => $etabs,
            'directeurs' => $this->contacts->directeursParEtablissement(),
            // Colonne « Contrôle » : doublons/anomalies calcules sur tous les dossiers actifs.
            'meta' => $this->analyse->metas($aVerifier, $etabs, $groupes),
            'controle' => true,
        ]);
    }

    /**
     * Onglet « Methode » : le socle herite d'un cote, les renforcements de l'autre.
     *
     * POURQUOI CET ECRAN EXISTE. Un jury doit pouvoir lire, sans nous croire sur
     * parole, ce qui vient du circuit de production et ce qui a ete ajoute dans
     * cette copie. Les chiffres ne sont pas ecrits dans le gabarit : ils sont
     * COMPTES sur le registre, qui lui-meme verifie que chaque controle existe
     * encore dans le code. Un controle retire ferait bouger le chiffre affiche.
     */
    #[Route('/methode', name: 'app_remboursement_methode', methods: ['GET'])]
    public function methode(): Response
    {
        $natures = ['bloquant' => 0, 'alerte' => 0, 'informatif' => 0];
        foreach (RegistreControlesCommand::REGISTRE as $controle) {
            ++$natures[(string) $controle['nature']];
        }
        $natures['total'] = \count(RegistreControlesCommand::REGISTRE);

        return $this->render('remboursement/methode.html.twig', [
            'nbAVerifier' => $this->nbAVerifier(),
            'herites' => $natures,
            'renforcements' => RegistreControlesCommand::RENFORCEMENTS,
        ]);
    }

    /**
     * Onglet "Suivi dossier" : tous les dossiers (hors verification), recherche/filtres,
     * scroll infini, panneau de detail. fragment=1 -> uniquement les lignes.
     */
    #[Route('/suivi', name: 'app_remboursement_suivi', methods: ['GET'])]
    public function suivi(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $motif = DossierMotif::tryFrom((string) $request->query->get('motif', ''));
        $statut = StatutSecretaire::tryFrom((string) $request->query->get('statut', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $tous = $this->dossiers->tousFiltre('' !== $q ? $q : null, $motif, $statut, $page);

        if ($request->query->getBoolean('fragment')) {
            return $this->render('remboursement/_liste_rows.html.twig', [
                'dossiers' => $tous,
                'etabs' => $this->mapEtablissements(),
                'directeurs' => $this->contacts->directeursParEtablissement(),
                'mode' => 'panneau',
            ]);
        }

        $total = $this->dossiers->compterTousFiltre('' !== $q ? $q : null, $motif, $statut);

        // "Verification comptable" est sur l'autre onglet -> retiree des options de filtre.
        $statutsFiltre = array_values(array_filter(
            StatutSecretaire::pourFiltre(),
            static fn (StatutSecretaire $s): bool => StatutSecretaire::VERIFICATION !== $s,
        ));

        return $this->render('remboursement/suivi.html.twig', [
            'tous' => $tous,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / DossierRepository::PAR_PAGE)),
            'q' => $q,
            'motif' => $motif,
            'statut' => $statut,
            'motifs' => DossierMotif::cases(),
            'statuts' => $statutsFiltre,
            'etabs' => $this->mapEtablissements(),
            'directeurs' => $this->contacts->directeursParEtablissement(),
            'nbAVerifier' => $this->nbAVerifier(),
            // Recap chiffre (tous les dossiers) : payé par motif, refusés, total.
            'recap' => $this->dossiers->recap(),
            // Les six files de PRODUCTION du directeur comptable. Ce ne sont pas
            // des indicateurs a contempler : chaque chiffre est un lien vers la
            // file filtree correspondante, et il porte le statut qui la definit.
            'files' => $this->filesDeProduction(),
        ]);
    }

    /**
     * Les six files de production, avec leur compte et le filtre qui les ouvre.
     *
     * Une file, ici, n'est pas une categorie d'analyse : c'est un ensemble de
     * dossiers qui attendent un geste, et le chiffre mene a la liste. Les
     * « incidents » regroupent ce qui est sorti du parcours normal -- doublon,
     * fraude, erreur de generation --, parce que ce sont les dossiers dont
     * personne ne s'occupe si on ne les compte pas.
     *
     * @return list<array{cle: string, libelle: string, nombre: int, statut: string}>
     */
    private function filesDeProduction(): array
    {
        $files = [
            ['cle' => 'verification', 'libelle' => 'À vérifier',
                'statuts' => StatutSecretaire::VERIFICATION->statuts(), 'statut' => StatutSecretaire::VERIFICATION->value],
            ['cle' => 'correction', 'libelle' => 'À corriger',
                'statuts' => StatutSecretaire::CORRECTION->statuts(), 'statut' => StatutSecretaire::CORRECTION->value],
            ['cle' => 'attente_directeur', 'libelle' => 'Attente directeur',
                'statuts' => StatutSecretaire::ATTENTE_DIRECTEUR->statuts(), 'statut' => StatutSecretaire::ATTENTE_DIRECTEUR->value],
            ['cle' => 'paiement', 'libelle' => 'Prêts à payer',
                'statuts' => StatutSecretaire::PAIEMENT->statuts(), 'statut' => StatutSecretaire::PAIEMENT->value],
            ['cle' => 'bloques', 'libelle' => 'Bloqués',
                'statuts' => [DossierStatut::CAS_ICAR, DossierStatut::REFUSE], 'statut' => StatutSecretaire::REFUSE->value],
            ['cle' => 'incidents', 'libelle' => 'Incidents',
                'statuts' => [DossierStatut::DOUBLON, DossierStatut::FRAUDE, DossierStatut::ERREUR_GENERATION],
                'statut' => StatutSecretaire::REFUSE->value],
        ];

        $sortie = [];
        foreach ($files as $f) {
            $sortie[] = [
                'cle' => $f['cle'],
                'libelle' => $f['libelle'],
                'nombre' => $this->dossiers->compterParStatuts($f['statuts']),
                'statut' => $f['statut'],
            ];
        }

        return $sortie;
    }

    private function nbAVerifier(): int
    {
        return $this->dossiers->compterParStatuts(StatutSecretaire::VERIFICATION->statuts());
    }

    /**
     * Une ligne "a verifier" rendue seule : insertion EN DIRECT dans la section
     * "A verifier" quand un dossier arrive dans la file (Mercure -> topic partage).
     */
    #[Route('/ligne/{id}', name: 'app_remboursement_ligne', methods: ['GET'], requirements: ['id' => '\d+|__ID__'])]
    public function ligne(Dossier $dossier): Response
    {
        $etabs = $this->mapEtablissements();
        $groupes = $this->analyse->groupes($this->dossiers->nonRefuses(), $etabs);

        return $this->render('remboursement/_liste_rows.html.twig', [
            'dossiers' => [$dossier],
            'etabs' => $etabs,
            'directeurs' => $this->contacts->directeursParEtablissement(),
            'mode' => 'page',
            'meta' => $this->analyse->metas([$dossier], $etabs, $groupes),
            'controle' => true,
        ]);
    }

    /**
     * Carte code etablissement -> libelle (nom), pour afficher le nom plutot que le
     * code dans "Tous les dossiers". Peu d'etablissements : une passe suffit.
     *
     * @return array<string, string>
     */
    private function mapEtablissements(): array
    {
        $map = [];
        foreach ($this->etablissements->rechercher() as $etab) {
            $map[$etab->getCodeEtab()] = $etab->getLibelle();
        }

        return $map;
    }
}
