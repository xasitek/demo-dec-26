<?php

declare(strict_types=1);

namespace App\Remboursement\Service;

use App\Remboursement\Entity\Dossier;
use DateTimeImmutable;
use DomainException;

/**
 * Genere un virement SEPA au format ISO 20022 pain.001.001.03 (une transaction
 * par fichier), fidele au format produit aujourd'hui par N8N.
 *
 *   DEBITEUR  = l'etablissement Synthauto (donneur d'ordre)
 *   CREDITEUR = le client rembourse
 *
 * Regle d'or : on utilise les valeurs CONTROLEES (corrigees par l'IA/comptable)
 * quand elles existent, sinon les valeurs brutes. Validations bloquantes sur les
 * coordonnees et le montant. Remplace le JS ecrit a la main dans N8N.
 * Voir docs/MODULE_REMBOURSEMENT.md (4.5).
 */
final class GenerateurSepa
{
    /**
     * @param array{nom: string, iban: string, bic: string} $etablissement coordonnees SEPA du debiteur
     *
     * @return array{xml: string, nomFichier: string, libelle: string}
     */
    public function construire(Dossier $dossier, array $etablissement, ?string $libellePaiement = null): array
    {
        $nomEtab = trim($etablissement['nom']);
        $ibanEtab = self::compacter($etablissement['iban']);
        $bicEtab = strtoupper(trim($etablissement['bic']));

        // Valeurs RETENUES (validees par la comptable) en priorite.
        $nomClient = self::nettoyerNom((string) ($dossier->getValideNom() ?: $dossier->getControleNom() ?: $dossier->getNomClient()));
        $ibanClient = self::compacter((string) ($dossier->getValideIban() ?: $dossier->getControleIban() ?: $dossier->getIbanClient()));
        $bicClient = strtoupper(trim((string) ($dossier->getValideBic() ?: $dossier->getControleBic() ?: $dossier->getBicClient())));
        $montantFloat = (float) str_replace(',', '.', (string) ($dossier->getValideMontant() ?: $dossier->getControleMontant() ?: $dossier->getMontant()));
        $montant = number_format($montantFloat, 2, '.', '');

        // Validations bloquantes (debiteur ET crediteur), alignees sur N8N.
        self::exigerIban($ibanEtab, 'IBAN de l\'etablissement (debiteur)');
        self::exigerBic($bicEtab, 'BIC de l\'etablissement (debiteur)');
        self::exigerIban($ibanClient, 'IBAN du client (crediteur)');
        self::exigerBic($bicClient, 'BIC du client (crediteur)');
        if ($montantFloat <= 0.0) {
            throw new DomainException('Le montant du virement doit etre strictement positif.');
        }
        if ('' === $nomEtab || '' === $nomClient) {
            throw new DomainException('Nom du debiteur et du beneficiaire requis.');
        }

        $reference = $dossier->getReference();
        // Motif du virement (Ustrd, 140 car.) = libelle STRUCTURE ; identifiant de
        // transaction (InstrId/EndToEndId, 35 car.) = reference du dossier (unique banque).
        $libelle = substr($libellePaiement ?? self::libelle($dossier), 0, 140);
        $idTx = substr($reference, 0, 35);
        $horodatage = new DateTimeImmutable();
        $msgId = 'REMB-'.$horodatage->format('YmdHis').'-'.$reference;
        $dateExec = $horodatage->format('Y-m-d');
        $creDtTm = $horodatage->format('Y-m-d\TH:i:s');

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">
          <CstmrCdtTrfInitn>
            <GrpHdr>
              <MsgId>{$msgId}</MsgId>
              <CreDtTm>{$creDtTm}</CreDtTm>
              <NbOfTxs>1</NbOfTxs>
              <CtrlSum>{$montant}</CtrlSum>
              <InitgPty><Nm>{$this->esc($nomEtab)}</Nm></InitgPty>
            </GrpHdr>
            <PmtInf>
              <PmtInfId>{$msgId}</PmtInfId>
              <PmtMtd>TRF</PmtMtd>
              <NbOfTxs>1</NbOfTxs>
              <CtrlSum>{$montant}</CtrlSum>
              <PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf>
              <ReqdExctnDt>{$dateExec}</ReqdExctnDt>
              <Dbtr><Nm>{$this->esc($nomEtab)}</Nm></Dbtr>
              <DbtrAcct><Id><IBAN>{$ibanEtab}</IBAN></Id></DbtrAcct>
              <DbtrAgt><FinInstnId><BIC>{$bicEtab}</BIC></FinInstnId></DbtrAgt>
              <ChrgBr>SLEV</ChrgBr>
              <CdtTrfTxInf>
                <PmtId><InstrId>{$this->esc($idTx)}</InstrId><EndToEndId>{$this->esc($idTx)}</EndToEndId></PmtId>
                <Amt><InstdAmt Ccy="EUR">{$montant}</InstdAmt></Amt>
                <CdtrAgt><FinInstnId><BIC>{$bicClient}</BIC></FinInstnId></CdtrAgt>
                <Cdtr><Nm>{$this->esc($nomClient)}</Nm></Cdtr>
                <CdtrAcct><Id><IBAN>{$ibanClient}</IBAN></Id></CdtrAcct>
                <RmtInf><Ustrd>{$this->esc($libelle)}</Ustrd></RmtInf>
              </CdtTrfTxInf>
            </PmtInf>
          </CstmrCdtTrfInitn>
        </Document>
        XML;

        // Extension .txt (mime application/xml) comme le systeme actuel.
        $nomFichier = sprintf(
            '%sSEPA_%s-%s-%s.txt',
            \App\Remboursement\Enum\DossierMotif::TROP_PERCU === $dossier->getMotif() ? 'TP_' : '',
            self::slug((string) $dossier->getEtablissementCode()),
            self::slug($dossier->getValideIcar() ?: $dossier->getValideImmatriculation() ?: $dossier->getControleIcar() ?: $dossier->getControleImmatriculation() ?: $reference),
            $reference,
        );

        return ['xml' => self::desindenter($xml), 'nomFichier' => $nomFichier, 'libelle' => $libelle];
    }

    private function esc(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Libelle du virement (InstrId/EndToEnd/Ustrd), prefixe metier + reference, borne. */
    private static function libelle(Dossier $dossier): string
    {
        $cle = $dossier->getValideImmatriculation() ?: $dossier->getValideIcar() ?: $dossier->getControleImmatriculation() ?: $dossier->getControleIcar() ?: $dossier->getReference();
        $libelle = sprintf('%s %s %s', $dossier->getMotif()->prefixeComptable(), $cle, $dossier->getReference());

        return substr(trim($libelle), 0, 35);
    }

    private static function compacter(string $valeur): string
    {
        return strtoupper(str_replace(' ', '', trim($valeur)));
    }

    /** Retire civilite + accents, borne a 70 caracteres (contrainte SEPA Nm). */
    private static function nettoyerNom(string $nom): string
    {
        $nom = trim($nom);
        $nom = preg_replace('/^\s*(M\.|Mr|Mme|Mlle|Monsieur|Madame|Mademoiselle)\s+/iu', '', $nom) ?? $nom;
        $translit = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom);
        if (false !== $translit) {
            $nom = $translit;
        }
        $nom = preg_replace('/[^A-Za-z0-9 \/\-?:().,\x27+]/', ' ', $nom) ?? $nom;

        return substr(trim(preg_replace('/\s+/', ' ', $nom) ?? $nom), 0, 70);
    }

    private static function slug(string $valeur): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '', $valeur) ?? '';
    }

    private static function exigerIban(string $iban, string $libelle): void
    {
        if (1 !== preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            throw new DomainException(sprintf('%s invalide : "%s".', $libelle, $iban));
        }
    }

    private static function exigerBic(string $bic, string $libelle): void
    {
        if (1 !== preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            throw new DomainException(sprintf('%s invalide : "%s".', $libelle, $bic));
        }
    }

    /** Retire l'indentation du heredoc pour un XML propre. */
    private static function desindenter(string $xml): string
    {
        $lignes = array_map(static fn (string $l): string => ltrim($l), explode("\n", trim($xml)));

        return implode("\n", $lignes);
    }
}
