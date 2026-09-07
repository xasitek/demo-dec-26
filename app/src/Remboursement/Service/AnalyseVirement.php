<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;

/**
 * Detection des doublons de virement et des anomalies (etablissement / IBAN / BIC) sur
 * un ensemble de dossiers. Partage entre l'ecran MANAGER « Virements SEPA » et la file
 * COMPTABLE « A verifier » : chaque appelant fournit SA base de matching (les dossiers
 * consideres comme jumeaux potentiels) et la liste a annoter. La cle de doublon est
 * portee par CleVirement (immat seule pour un rachat, ICAR + client pour un trop-percu).
 */
final class AnalyseVirement
{
    /**
     * Regroupe la base par cle de virement : chaque groupe liste les dossiers (avec de
     * quoi les afficher et les ouvrir) qui partagent le meme beneficiaire au sens du virement.
     *
     * @param list<Dossier>         $base
     * @param array<string, string> $etabs code etablissement -> libelle (pour l'affichage)
     *
     * @return array<string, list<array{id: int, reference: string, client: string, etablissement: string, statut: string}>> cle virement -> dossiers du groupe
     */
    public function groupes(array $base, array $etabs = []): array
    {
        $map = [];
        foreach ($base as $d) {
            $cle = CleVirement::pour($d);
            if ('' !== $cle) {
                $code = (string) $d->getEtablissementCode();
                $map[$cle][] = [
                    'id' => (int) $d->getId(),
                    'reference' => $d->getReference(),
                    'client' => (string) ($d->getValideNom() ?: $d->getNomClient()),
                    'etablissement' => $etabs[$code] ?? ('' !== $code ? $code : '—'),
                    'statut' => $d->getStatut()->statutSecretaire()->libelle(),
                ];
            }
        }

        return $map;
    }

    /**
     * @param list<Dossier>                                                                                                 $dossiers
     * @param array<string, string>                                                                                         $etabs
     * @param array<string, list<array{id: int, reference: string, client: string, etablissement: string, statut: string}>> $groupes
     *
     * @return array<int, array{libelle: string, ibanMasque: string, bic: string, immatIcar: string, cleDoublon: string, doublon: bool, doublonRefs: list<string>, doublonJumeaux: list<array{id: int, reference: string, client: string, etablissement: string, statut: string}>, doublonGroupe: list<array{id: int, reference: string, client: string, etablissement: string, statut: string}>, anomalies: list<string>}>
     */
    public function metas(array $dossiers, array $etabs, array $groupes): array
    {
        $out = [];
        foreach ($dossiers as $d) {
            $out[(int) $d->getId()] = $this->meta($d, $etabs, $groupes);
        }

        return $out;
    }

    /**
     * @param array<string, string>                                                                                         $etabs
     * @param array<string, list<array{id: int, reference: string, client: string, etablissement: string, statut: string}>> $groupes
     *
     * @return array{libelle: string, ibanMasque: string, bic: string, immatIcar: string, cleDoublon: string, doublon: bool, doublonRefs: list<string>, doublonJumeaux: list<array{id: int, reference: string, client: string, etablissement: string, statut: string}>, doublonGroupe: list<array{id: int, reference: string, client: string, etablissement: string, statut: string}>, anomalies: list<string>}
     */
    public function meta(Dossier $dossier, array $etabs, array $groupes): array
    {
        $code = (string) $dossier->getEtablissementCode();
        $rachat = DossierMotif::RACHAT_SEC === $dossier->getMotif();

        $bic = trim((string) ($dossier->getValideBic() ?: $dossier->getControleBic() ?: $dossier->getBicClient()));
        $ibanMasque = null !== $dossier->getValideIban()
            ? $dossier->getValideIbanMasque()
            : (null !== $dossier->getControleIban() ? $dossier->getControleIbanMasque() : $dossier->getIbanClientMasque());

        // IBAN et BIC sont OBLIGATOIRES au depot (valides cote serveur) : ils ne peuvent pas
        // manquer a ce stade. On ne signale donc que l'etablissement inconnu (necessaire au
        // debiteur SEPA) ; le reste du controle porte sur le DOUBLON.
        $anomalies = [];
        if ('' === $code || !isset($etabs[$code])) {
            $anomalies[] = 'Établissement inconnu';
        }

        $cleVirement = CleVirement::pour($dossier);
        $groupe = '' !== $cleVirement && isset($groupes[$cleVirement]) ? $groupes[$cleVirement] : [];
        $jumeaux = array_values(array_filter(
            $groupe,
            static fn (array $g): bool => $g['reference'] !== $dossier->getReference(),
        ));
        $doublonRefs = array_map(static fn (array $g): string => $g['reference'], $jumeaux);

        return [
            'libelle' => LibellePaiement::pour($dossier, $etabs[$code] ?? $code),
            'ibanMasque' => $ibanMasque,
            'bic' => '' !== $bic ? $bic : '—',
            'immatIcar' => $rachat
                ? (string) ($dossier->getValideImmatriculation() ?: $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation())
                : (string) ($dossier->getValideIcar() ?: $dossier->getControleIcar() ?: $dossier->getCodeIcar()),
            'cleDoublon' => $cleVirement,
            'doublon' => [] !== $jumeaux,
            'doublonRefs' => $doublonRefs,
            'doublonJumeaux' => $jumeaux,
            // Groupe COMPLET (self + jumeaux), seulement si doublon : liste du panneau glissant.
            'doublonGroupe' => [] !== $jumeaux ? $groupe : [],
            'anomalies' => $anomalies,
        ];
    }
}
