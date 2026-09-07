<?php

declare(strict_types=1);

namespace App\Livraison\Controller;

use App\Livraison\Entity\Declaration;
use App\Livraison\Entity\Piece;
use App\Livraison\Enum\TypePiece;
use App\Livraison\Repository\ConcessionRepository;
use App\Livraison\Repository\DeclarationRepository;
use App\Livraison\Repository\VehiculeALivrerRepository;
use App\Livraison\Service\ValidationPieces;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Espace Livraison du poste secretaire : declarer un vehicule livre et suivre ses
 * declarations. Remplace les trente-cinq formulaires Google et l'Apps Script qui
 * tenait leurs listes a jour.
 *
 * L'ecran reproduit les TROIS niveaux du formulaire remplace, releves sur le
 * circuit reel le 2026-09-02 :
 *
 *   1. la concession — il existait un formulaire Google par concession, filtre sur
 *      la colonne `codeSoc` de l'onglet `ESPACE LIVREUR`, qui est notre
 *      `code_societe`. Le compte Google de la secretaire ne porte pas cette
 *      information, donc elle la choisit ; son dernier choix est ensuite propose ;
 *   2. le loueur — la liste deroulante « Choisissez un client : », dont le libelle
 *      portait le reste a declarer (`OVERLEASE (24 non livres)`) ;
 *   3. les vehicules de ce loueur, en selection multiple.
 *
 * Le niveau loueur n'est pas une commodite d'affichage : les trois pieces jointes
 * valent pour tous les vehicules coches, donc un depot ne peut porter que sur un
 * seul loueur — sans quoi un meme PV de livraison partirait dans les dossiers de
 * plusieurs loueurs. La regle est refaite cote serveur a la validation.
 *
 * La liste des vehicules vient de `livraison.v_a_livrer`, donc de la comptabilite :
 * la secretaire ne cherche pas un vehicule, on lui propose les siens.
 *
 * Acces : le formulaire (GET) est PUBLIC, comme celui de Remboursement — la
 * secretaire ne se connecte qu'au moment de valider. Le depot reel (POST) et le
 * suivi personnel exigent ROLE_SECRETAIRE **et** le module : le confinement
 * n'agit que sur les GET, donc sans cette garde un POST passerait alors que
 * l'espace n'est meme pas affiche a qui n'a pas le module.
 */
#[Route('/livraison')]
final class DeclarationController extends AbstractController
{
    public function __construct(
        private readonly VehiculeALivrerRepository $vehicules,
        private readonly ConcessionRepository $concessions,
        private readonly DeclarationRepository $declarations,
        private readonly EntityManagerInterface $em,
        private readonly ValidationPieces $validation,
    ) {
    }

    #[Route('/declarer', name: 'app_livraison_declarer', methods: ['GET'])]
    public function declarer(Request $request): Response
    {
        $concessions = $this->concessions->avecRestants();
        $connues = array_column($concessions, 'code_societe');

        $concession = trim((string) $request->query->get('soc', ''));

        // `?etab=` reste accepte : c'etait le parametre de la version a deux niveaux,
        // et un etablissement designe sans ambiguite sa concession.
        if ('' === $concession) {
            $etab = trim((string) $request->query->get('etab', ''));
            if ('' !== $etab) {
                $concession = (string) $this->concessions->concessionDe($etab);
            }
        }

        // Rien dans l'URL : on propose celle de sa derniere declaration.
        if ('' === $concession) {
            $concession = $this->derniereConcession() ?? '';
        }

        if ('' !== $concession && !\in_array($concession, $connues, true)) {
            $concession = '';
        }

        $loueurs = '' === $concession ? [] : $this->vehicules->loueursDeLaConcession($concession);

        $codePayeur = trim((string) $request->query->get('loueur', ''));
        if ('' !== $codePayeur && !\in_array($codePayeur, array_column($loueurs, 'code_payeur'), true)) {
            $codePayeur = '';
        }

        // Un seul loueur dans la concession : le niveau n'a pas lieu d'etre, on
        // enchaine directement sur ses vehicules.
        if ('' === $codePayeur && 1 === \count($loueurs)) {
            $codePayeur = $loueurs[0]['code_payeur'];
        }

        $aDeclarer = [];
        $declares = [];
        if ('' !== $concession && '' !== $codePayeur) {
            foreach ($this->vehicules->parConcessionEtLoueur($concession, $codePayeur) as $ligne) {
                // Ce qui est deja declare ne doit plus etre proposable : c'est le
                // « livre / non livre » du tableur, tenu par la base au lieu d'un
                // VLOOKUP circulaire.
                if (1 === (int) $ligne['deja_declare']) {
                    $declares[] = $ligne;
                } else {
                    $aDeclarer[] = $ligne;
                }
            }
        }

        return $this->render('livraison/declarer.html.twig', [
            'concessions' => $concessions,
            'concession' => $concession,
            'loueurs' => $loueurs,
            'code_payeur' => $codePayeur,
            'a_declarer' => $aDeclarer,
            'declares' => $declares,
            'pieces' => TypePiece::requises(),
        ]);
    }

    #[Route('/declarer', name: 'app_livraison_declarer_valider', methods: ['POST'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    #[IsGranted('MODULE_LIVRAISON')]
    public function valider(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('livraison_declarer', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, merci de recommencer.');

            return $this->redirectToRoute('app_livraison_declarer');
        }

        $concession = trim((string) $request->request->get('concession', ''));
        $codePayeur = trim((string) $request->request->get('loueur', ''));
        $retour = ['soc' => $concession, 'loueur' => $codePayeur];

        /** @var list<string> $choisis */
        $choisis = array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) $request->request->all('vehicules'),
        ))));

        if ('' === $concession || '' === $codePayeur) {
            $this->addFlash('error', 'Choisissez une concession et un loueur.');

            return $this->redirectToRoute('app_livraison_declarer', $retour);
        }

        if ([] === $choisis) {
            $this->addFlash('error', 'Cochez au moins un véhicule.');

            return $this->redirectToRoute('app_livraison_declarer', $retour);
        }

        // Les vehicules d'abord, les fichiers ensuite : inutile de lire jusqu'a trois
        // pieces en memoire pour refuser ensuite la selection.
        $aCreer = [];
        $etrangers = 0;
        $deja = 0;
        foreach ($choisis as $identifiant) {
            // Le navigateur n'est pas une source de verite : on relit le vehicule dans
            // la vue, contraint a la concession ET au loueur du depot.
            $vehicule = $this->vehicules->vehiculeDansConcession($concession, $codePayeur, $identifiant);
            if (null === $vehicule) {
                ++$etrangers;
                continue;
            }
            if (1 === (int) $vehicule['deja_declare']) {
                ++$deja;
                continue;
            }
            $aCreer[] = $vehicule;
        }

        // Un depot vaut un seul loueur. Si la selection sort du perimetre, on refuse
        // l'ensemble : enregistrer la moitie d'un depot serait pire que rien.
        if ($etrangers > 0) {
            $this->addFlash('error', sprintf(
                'La sélection contient %s qui ne %s pas à ce loueur. Un dépôt ne peut porter que sur un seul loueur : recommencez la sélection.',
                1 === $etrangers ? 'un véhicule' : $etrangers.' véhicules',
                1 === $etrangers ? 'appartient' : 'appartiennent',
            ));

            return $this->redirectToRoute('app_livraison_declarer', $retour);
        }

        if ([] === $aCreer) {
            $this->addFlash('error', $deja > 0
                ? 'Ces véhicules ont déjà été déclarés entre-temps.'
                : 'Aucun véhicule à déclarer.');

            return $this->redirectToRoute('app_livraison_declarer', $retour);
        }

        // Liste de couples (type, fichier) plutot qu'un tableau indexe par valeur :
        // l'analyse statique voit ainsi que chaque piece requise est bien presente.
        /** @var list<array{TypePiece, UploadedFile}> $fichiers */
        $fichiers = [];
        foreach (TypePiece::requises() as $type) {
            $fichier = $request->files->get('piece_'.$type->value);
            if (!$fichier instanceof UploadedFile) {
                $this->addFlash('error', sprintf('La pièce « %s » est obligatoire.', $type->libelle()));

                return $this->redirectToRoute('app_livraison_declarer', $retour);
            }
            $erreur = $this->validation->refus(
                $type,
                $fichier->isValid(),
                $fichier->getSize(),
                $fichier->getMimeType(),
            );
            if (null !== $erreur) {
                $this->addFlash('error', $erreur);

                return $this->redirectToRoute('app_livraison_declarer', $retour);
            }
            $fichiers[] = [$type, $fichier];
        }

        $email = (string) $this->getUser()?->getUserIdentifier();
        $commentaire = trim((string) $request->request->get('commentaire', ''));
        $faites = 0;

        try {
            foreach ($aCreer as $vehicule) {
                $declaration = new Declaration(
                    identifiantVehicule: $vehicule['identifiant_vehicule'],
                    loueur: $vehicule['loueur'],
                    codePayeur: $vehicule['code_payeur'],
                    codeEtab: $vehicule['code_etab'],
                    declareePar: $email,
                    immatriculation: $vehicule['immatriculation'],
                    vin: $vehicule['vin'],
                );
                $declaration->setCommentaire($commentaire);

                foreach ($fichiers as [$type, $fichier]) {
                    new Piece(
                        declaration: $declaration,
                        type: $type,
                        contenu: (string) file_get_contents($fichier->getPathname()),
                        nomFichier: $fichier->getClientOriginalName(),
                        mimeType: $fichier->getMimeType() ?? 'application/octet-stream',
                    );
                }

                $this->em->persist($declaration);
                ++$faites;
            }

            $this->em->flush();
        } catch (Throwable) {
            $this->addFlash('error', "La déclaration n'a pas pu être enregistrée. Merci de réessayer.");

            return $this->redirectToRoute('app_livraison_declarer', $retour);
        }

        $this->addFlash('success', sprintf(
            '%s déclarée%s.',
            1 === $faites ? 'Une livraison' : $faites.' livraisons',
            1 === $faites ? '' : 's',
        ));

        return $this->redirectToRoute('app_livraison_mes_declarations');
    }

    #[Route('/mes-declarations', name: 'app_livraison_mes_declarations', methods: ['GET'])]
    #[IsGranted('ROLE_SECRETAIRE')]
    #[IsGranted('MODULE_LIVRAISON')]
    public function mesDeclarations(): Response
    {
        $email = (string) $this->getUser()?->getUserIdentifier();

        return $this->render('livraison/mes_declarations.html.twig', [
            'declarations' => $this->declarations->pour($email),
        ]);
    }

    /**
     * La concession de sa derniere declaration. Son compte ne porte pas cette
     * information, mais ses depots passes la donnent : elle ne rechoisit donc qu'une
     * fois, pas a chaque declaration.
     */
    private function derniereConcession(): ?string
    {
        $email = $this->getUser()?->getUserIdentifier();
        if (null === $email) {
            return null;
        }

        $precedentes = $this->declarations->pour($email, 1);

        return [] === $precedentes ? null : $this->concessions->concessionDe($precedentes[0]->getCodeEtab());
    }
}
