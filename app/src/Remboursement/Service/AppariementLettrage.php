<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use DateTimeImmutable;

/**
 * Appariement d'une ligne bancaire NON LETTREE (remboursement.v_lettrage) avec le
 * dossier de remboursement paye le plus probable : score 0-100 + raisons affichables.
 *
 * Le bareme est repris VERBATIM du dashboard historique (endpoint
 * `/api/lettrage/match`), pour que la comptable retrouve les memes verdicts. Deux
 * differences assumees :
 *   - cote dossier on lit `valide_* -> controle_* -> saisie` (l'ordre de verite du
 *     module) la ou l'ancien lisait `controle_* -> brut` : une source de plus ;
 *   - la date de reference est `paye_le` (equivalent de `date_validation_compta`).
 *
 * Les poids dependent du TYPE de la ligne : une immatriculation identique est
 * decisive sur un rachat sec (+60) mais seulement indicative sur un trop-percu
 * (+35), ou c'est le code ICAR et le montant exact qui portent le signal. Un motif
 * incoherent (ligne TP face a un dossier de rachat) retire 25 points : c'est le seul
 * signal franchement negatif, avec l'ecart de date au-dela d'un mois.
 *
 * Lecture seule : aucun effet de bord, rien n'est ecrit ni lettre automatiquement.
 * C'est une AIDE a la decision, la comptable garde la main.
 */
final class AppariementLettrage
{
    /** Ecart en euros sous lequel deux montants sont juges identiques (arrondi au centime). */
    private const TOLERANCE_MONTANT_EXACT = 0.51;

    /** Ecart en euros sous lequel deux montants sont juges proches. */
    private const TOLERANCE_MONTANT_PROCHE = 2.0;

    /**
     * Meilleur candidat pour une ligne, ou null si aucun dossier n'est fourni.
     *
     * @param array<string, mixed> $ligne    une ligne de remboursement.v_lettrage
     * @param iterable<Dossier>    $dossiers dossiers payes a confronter
     *
     * @return array{dossier: Dossier, score: int, raisons: list<string>}|null
     */
    public function meilleur(array $ligne, iterable $dossiers): ?array
    {
        $meilleur = null;
        foreach ($dossiers as $dossier) {
            $resultat = $this->scorer($ligne, $dossier);
            if (null === $meilleur || $resultat['score'] > $meilleur['score']) {
                $meilleur = ['dossier' => $dossier, 'score' => $resultat['score'], 'raisons' => $resultat['raisons']];
            }
        }

        return $meilleur;
    }

    /**
     * Score 0-100 d'appariement entre une ligne bancaire et un dossier, avec les
     * raisons qui l'expliquent (affichees telles quelles a la comptable).
     *
     * @param array<string, mixed> $ligne
     *
     * @return array{score: int, raisons: list<string>}
     */
    public function scorer(array $ligne, Dossier $dossier): array
    {
        $type = strtoupper(trim((string) ($ligne['type'] ?? '')));
        $rachat = 'RBC' === $type;
        $score = 0;
        $raisons = [];

        $libelleNorm = self::sansEspacesNiTirets((string) ($ligne['libelle'] ?? ''));

        // --- Coherence du motif avec le type bancaire ---
        $dossierRachat = DossierMotif::RACHAT_SEC === $dossier->getMotif();
        if ($rachat === $dossierRachat) {
            $score += $rachat ? 12 : 18;
            $raisons[] = $rachat ? 'Motif rachat sec' : 'Motif trop-perçu';
        } else {
            // Seul signal franchement disqualifiant : les deux types ne se melangent pas.
            $score -= 25;
        }

        // --- Immatriculation : signal le plus fort sur un rachat sec ---
        // `numimmat` ET `numvin` peuvent l'un comme l'autre porter une plaque cote
        // banque : on confronte les deux a l'immatriculation du dossier.
        $immatDossier = self::normaliserImmat($this->immatriculation($dossier));
        $candidats = array_values(array_filter([
            self::normaliserImmat((string) ($ligne['numimmat'] ?? '')),
            self::normaliserImmat((string) ($ligne['numvin'] ?? '')),
        ], static fn (string $v): bool => '' !== $v));

        $immatTrouvee = false;
        if ('' !== $immatDossier && [] !== $candidats) {
            if (\in_array($immatDossier, $candidats, true)) {
                $score += $rachat ? 60 : 35;
                $raisons[] = 'Immatriculation identique : '.$this->immatriculation($dossier);
                $immatTrouvee = true;
            } elseif (\strlen($immatDossier) >= 5 && str_contains($libelleNorm, $immatDossier)) {
                $score += $rachat ? 45 : 25;
                $raisons[] = 'Immatriculation présente dans le libellé';
                $immatTrouvee = true;
            }
        }
        // Ligne sans champ immat renseigne : la plaque est souvent DANS le libelle.
        if (!$immatTrouvee && '' !== $immatDossier && [] === $candidats
            && \strlen($immatDossier) >= 5 && str_contains($libelleNorm, $immatDossier)) {
            $score += $rachat ? 40 : 22;
            $raisons[] = 'Immatriculation détectée dans le libellé';
        }

        // --- Montant : signal fort sur un trop-percu ---
        $montantDossier = (float) ($dossier->getValideMontant() ?: $dossier->getControleMontant() ?: $dossier->getMontant());
        $montantLigne = abs((float) ($ligne['montant'] ?? 0));
        if ($montantDossier > 0.0 && $montantLigne > 0.0) {
            $ecart = abs($montantLigne - $montantDossier);
            if ($ecart < self::TOLERANCE_MONTANT_EXACT) {
                $score += $rachat ? 12 : 32;
                $raisons[] = 'Montant exact : '.self::euros($montantDossier);
            } elseif ($ecart < self::TOLERANCE_MONTANT_PROCHE) {
                $score += $rachat ? 7 : 18;
                $raisons[] = sprintf('Montant proche : %s (écart %s)', self::euros($montantDossier), self::euros($ecart));
            } elseif ($ecart / max($montantLigne, 1.0) < 0.05) {
                $score += 6;
                $raisons[] = 'Montant à ±5 %';
            }
        }

        // --- Etablissement ---
        $etabDossier = self::normaliserEtab((string) $dossier->getEtablissementCode());
        $etabLigne = self::normaliserEtab((string) ($ligne['codeetab'] ?? ''));
        if ('' !== $etabDossier && $etabDossier === $etabLigne) {
            $score += 22;
            $raisons[] = 'Établissement '.trim((string) ($ligne['codeetab'] ?? ''));
        }

        // --- Compte bancaire (ligne) contre code ICAR (dossier) : signal des trop-percus ---
        $compteLigne = self::sansEspaces((string) ($ligne['compte'] ?? ''));
        $icarBrut = trim((string) ($dossier->getValideIcar() ?: $dossier->getControleIcar() ?: $dossier->getCodeIcar()));
        $icarDossier = self::sansEspaces($icarBrut);
        if ('' !== $compteLigne && '' !== $icarDossier) {
            if ($compteLigne === $icarDossier) {
                $score += $rachat ? 18 : 35;
                $raisons[] = 'Code ICAR identique : '.$icarBrut;
            } elseif (\strlen($compteLigne) >= 4
                && (str_contains($icarDossier, $compteLigne) || str_contains($compteLigne, $icarDossier))) {
                $score += $rachat ? 9 : 18;
                $raisons[] = 'Code ICAR partiel : '.$icarBrut;
            }
        }

        // --- Date : ecriture bancaire contre paiement du dossier ---
        $dateLigne = self::date($ligne['date_piece'] ?? null);
        $datePaye = $dossier->getPayeLe();
        if (null !== $dateLigne && null !== $datePaye) {
            $jours = abs((int) $dateLigne->diff($datePaye)->days);
            if (0 === $jours) {
                $score += 25;
                $raisons[] = 'Même jour : '.$datePaye->format('d/m/Y');
            } elseif (1 === $jours) {
                $score += 12;
                $raisons[] = 'Date à ±1 jour';
            } elseif ($jours <= 3) {
                $score += 5;
                $raisons[] = sprintf('Date à ±%d jours', $jours);
            } elseif ($jours > 30) {
                $score -= 8;
            }
        }

        // --- Nom du client ---
        $nomBrut = trim((string) ($dossier->getValideNom() ?: $dossier->getControleNom() ?: $dossier->getNomClient()));
        $nomDossier = self::normaliserNom($nomBrut);
        $nomLigne = self::normaliserNom((string) ($ligne['nom'] ?? ''));
        if ('' !== $nomDossier && '' !== $nomLigne) {
            if ($nomDossier === $nomLigne) {
                $score += 28;
                $raisons[] = 'Nom identique';
            } elseif (\strlen($nomLigne) >= 3
                && (str_contains($nomDossier, $nomLigne) || str_contains($nomLigne, $nomDossier))) {
                $score += 18;
                $raisons[] = 'Nom contenu : '.$nomBrut;
            } else {
                // Comparaison tolerante aux inversions prenom/nom et aux fautes de
                // frappe : n-grammes de 4 caracteres.
                $communs = \count(array_intersect(self::ngrammes($nomBrut), self::ngrammes((string) ($ligne['nom'] ?? ''))));
                if ($communs >= 2) {
                    $score += 10;
                    $raisons[] = 'Nom partiellement similaire';
                } elseif ($communs >= 1) {
                    $score += 4;
                }
            }
        }

        return ['score' => max(0, min(100, $score)), 'raisons' => $raisons];
    }

    /** Immatriculation retenue du dossier (valide > controle IA > saisie secretaire). */
    private function immatriculation(Dossier $dossier): string
    {
        return trim((string) ($dossier->getValideImmatriculation()
            ?: $dossier->getControleImmatriculation()
            ?: $dossier->getImmatriculation()));
    }

    private static function normaliserImmat(string $valeur): string
    {
        return (string) preg_replace('/[\s\-_]/', '', strtoupper(trim($valeur)));
    }

    private static function normaliserNom(string $valeur): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($valeur));
    }

    /**
     * Partie numerique du code etablissement, zeros de tete retires (« CG23 » et
     * « 023 » donnent tous deux « 23 ») : les deux cotes passent par la meme regle,
     * donc la comparaison reste juste malgre les formats heterogenes de Progiciel.
     */
    private static function normaliserEtab(string $valeur): string
    {
        $brut = strtoupper(trim($valeur));
        if ('' === $brut) {
            return '';
        }
        if (1 !== preg_match('/\d+/', $brut, $m)) {
            return $brut;
        }

        return ltrim($m[0], '0') ?: '0';
    }

    private static function sansEspaces(string $valeur): string
    {
        return (string) preg_replace('/\s+/', '', strtoupper($valeur));
    }

    private static function sansEspacesNiTirets(string $valeur): string
    {
        return (string) preg_replace('/[\s\-]/', '', strtoupper($valeur));
    }

    /**
     * N-grammes glissants de 4 caracteres du nom normalise (le nom entier si plus court).
     *
     * @return list<string>
     */
    private static function ngrammes(string $valeur): array
    {
        $norm = self::normaliserNom($valeur);
        if ('' === $norm) {
            return [];
        }
        if (\strlen($norm) < 4) {
            return [$norm];
        }

        $tokens = [];
        for ($i = 0, $max = \strlen($norm) - 4; $i <= $max; ++$i) {
            $tokens[substr($norm, $i, 4)] = true;
        }

        return array_keys($tokens);
    }

    /** Date d'une colonne de la vue (DATE Postgres renvoyee en chaine). */
    private static function date(mixed $valeur): ?DateTimeImmutable
    {
        if ($valeur instanceof DateTimeImmutable) {
            return $valeur;
        }
        $texte = trim((string) $valeur);

        return '' === $texte ? null : (DateTimeImmutable::createFromFormat('!Y-m-d', substr($texte, 0, 10)) ?: null);
    }

    private static function euros(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }
}
