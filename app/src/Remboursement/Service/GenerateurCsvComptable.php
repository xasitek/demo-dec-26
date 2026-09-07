<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use DateTimeImmutable;

/**
 * Genere le CSV d'ecriture comptable (OD) importe dans Gestion commerciale : deux lignes par
 * dossier (DEBIT compte client / CREDIT compte bancaire de l'etablissement).
 *
 * L'EN-TETE et l'ordre des colonnes sont repris A L'IDENTIQUE du systeme N8N actuel
 * (non-regression de l'import Gestion commerciale). Le code client ICAR est renseigne aux DEUX
 * motifs ; NumImmat n'existe qu'au rachat sec, prefixe "RBC //" (trop-percu : "TP //").
 * Valeurs controlees.
 *
 * NB (a confirmer sur un CSV Gestion commerciale reel avant go-live) : le TypeCompte de la
 * ligne de CREDIT et le format de DateOD ne sont pas documentes par N8N ; valeurs
 * prudentes ici (voir constantes), a valider. Voir docs/MODULE_REMBOURSEMENT.md (4.5).
 */
final class GenerateurCsvComptable
{
    /** En-tete EXACT attendu par Gestion commerciale (17 colonnes, 3 vides : 13, 15, 16). */
    private const ENTETE = 'SOCIETE;codeCompte;montant;Sens;codeClientICAR;TIERS;codeEtab;TypeCompte;Analytique;DateOD;RefPiece;LibellePiece;;NumImmat;;;urlScanPiece';

    private const COMPTE_CLIENT_DEFAUT = '4111000';
    private const COMPTE_BANQUE_DEFAUT = '5120200';
    private const TIERS_DEFAUT = 'COMPTANT';

    /**
     * @param array{societe: string, codeEtab: string, cinq?: string, codeComptable?: string, roleTiers?: string} $etablissement
     *
     * @return array{csv: string, nomFichier: string}
     */
    public function construire(Dossier $dossier, array $etablissement, ?string $urlScanPiece = null, ?string $libellePaiement = null): array
    {
        $rachat = DossierMotif::RACHAT_SEC === $dossier->getMotif();

        $societe = trim($etablissement['societe']);
        $codeEtab = str_pad(preg_replace('/\D+/', '', $etablissement['codeEtab']) ?? '', 3, '0', STR_PAD_LEFT);
        $compteClient = trim($etablissement['codeComptable'] ?? '') ?: self::COMPTE_CLIENT_DEFAUT;
        $compteBanque = trim($etablissement['cinq'] ?? '') ?: self::COMPTE_BANQUE_DEFAUT;
        $tiers = trim($etablissement['roleTiers'] ?? '') ?: self::TIERS_DEFAUT;

        // Valeurs RETENUES (validees par la comptable) en priorite : ce sont elles qui font foi.
        $montant = number_format((float) str_replace(',', '.', (string) ($dossier->getValideMontant() ?: $dossier->getControleMontant() ?: $dossier->getMontant())), 2, '.', '');
        $immat = $rachat ? (string) ($dossier->getValideImmatriculation() ?: $dossier->getControleImmatriculation() ?: $dossier->getImmatriculation() ?: '') : '';
        // Colonne codeClientICAR renseignee aux DEUX motifs : le code client fait partie de
        // l'ecriture, rachat sec compris (arbitrage 2026-09-03 ; elle etait vide avant).
        $icar = (string) ($dossier->getValideIcar() ?: $dossier->getControleIcar() ?: $dossier->getCodeIcar() ?: '');
        $nom = (string) ($dossier->getValideNom() ?: $dossier->getControleNom() ?: $dossier->getNomClient());
        $dateOd = (new DateTimeImmutable())->format('d/m/Y');
        $refPiece = $dossier->getReference();
        // Libelle STRUCTURE (detection doublons/anomalies) ; repli sur l'ancien format.
        $libelle = substr($libellePaiement ?? sprintf('%s %s %s', $dossier->getMotif()->prefixeComptable(), $nom, $rachat ? $immat : $icar), 0, 140);
        $scan = $urlScanPiece ?? '';

        // Colonnes communes (positions du header). '' = colonnes vides 13/15/16.
        $ligneDebit = [$societe, $compteClient, $montant, 'D', $icar, $tiers, $codeEtab, 'X', '', $dateOd, $refPiece, $libelle, '', $immat, '', '', $scan];
        $ligneCredit = [$societe, $compteBanque, $montant, 'C', $icar, $tiers, $codeEtab, 'G', '', $dateOd, $refPiece, $libelle, '', $immat, '', '', $scan];

        $csv = self::ENTETE."\n"
            .implode(';', array_map(self::echapper(...), $ligneDebit))."\n"
            .implode(';', array_map(self::echapper(...), $ligneCredit))."\n";

        $nomFichier = sprintf(
            '%sOD_%s_%s_%s.csv',
            $rachat ? '' : 'TP_',
            $codeEtab,
            self::slug($rachat ? $immat : $icar),
            $refPiece,
        );

        return ['csv' => $csv, 'nomFichier' => $nomFichier];
    }

    /** Neutralise le separateur et les sauts de ligne dans une valeur de cellule. */
    private static function echapper(string $valeur): string
    {
        return str_replace([';', "\r", "\n"], [',', ' ', ' '], trim($valeur));
    }

    private static function slug(string $valeur): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '', $valeur) ?? '';
    }
}
