<?php

declare(strict_types=1);

namespace App\Remboursement\Service\Ia;

use App\Remboursement\Entity\Dossier;
use App\Remboursement\Enum\DossierMotif;
use App\Remboursement\Service\CleDoublon;
use App\Remboursement\Service\ClientEloficash;

/**
 * Ecrit les colonnes controle_* du dossier a partir des champs extraits par l'IA
 * (regle d'or : jamais la saisie brute), puis calcule un verdict INDICATIF en
 * comparant brut vs extraction. Le comptable garde le dernier mot (surchargeable).
 *
 * Cas trop-percu PREREMPLI depuis le miroir (controleExtra['source_compte']) : le montant
 * NET + l'ICAR (n de compte) + le nom sont pris DIRECTEMENT dans la base (source de verite
 * comptable) ; l'OCR ne sert plus qu'a verifier l'IBAN du RIB.
 */
final class AgregateurControle
{
    public function __construct(private readonly ClientEloficash $eloficash)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $champsParType type de piece => champs extraits
     */
    public function agreger(Dossier $dossier, array $champsParType, ?string $par): void
    {
        /** @var array<string, mixed> $extra */
        $extra = $dossier->getControleExtra() ?? [];

        if (isset($champsParType['rib'])) {
            $rib = $champsParType['rib'];
            $dossier->setControleNom(self::s($rib['nom'] ?? null));
            $dossier->setControleIban(self::s($rib['iban'] ?? null));
            $dossier->setControleBic(self::s($rib['bic'] ?? null));
        }
        if (isset($champsParType['facture_achat_vo'])) {
            $f = $champsParType['facture_achat_vo'];
            $dossier->setControleMontant(NormalisateurControle::montant($f['montant'] ?? null));
            $dossier->setControleImmatriculation(self::s($f['immatriculation'] ?? null) ?: null);
            $extra['num_facture'] = self::s($f['num_facture'] ?? null);
            $extra['role_tiers'] = self::s($f['role_tiers'] ?? null);
            $extra['code_comptable'] = self::s($f['code_comptable'] ?? null);
            $extra['facture_icar'] = self::s($f['code_icar'] ?? null);
            $extra['facture_invalide'] = (bool) ($f['document_invalide'] ?? false);
        }
        if (isset($champsParType['estimation_salesforce'])) {
            $e = $champsParType['estimation_salesforce'];
            $extra['montant_coherence'] = NormalisateurControle::montant($e['montant_coherence'] ?? null);
            $extra['modifications_detectees'] = (bool) ($e['modifications_detectees'] ?? false);
        }
        if (isset($champsParType['carte_grise'])) {
            $c = $champsParType['carte_grise'];
            $extra['carte_grise_immat'] = self::s($c['immatriculation'] ?? null);
            $extra['carte_grise_manuscrite'] = (bool) ($c['ecriture_manuscrite'] ?? false);
            $extra['carte_grise_invalide'] = (bool) ($c['document_invalide'] ?? false);
        }
        if (isset($champsParType['certificat_situation'])) {
            $c = $champsParType['certificat_situation'];
            $extra['certificat_immat'] = self::s($c['immatriculation'] ?? null);
            $extra['vehicule_libre'] = (bool) ($c['vehicule_libre'] ?? false);
            $extra['certificat_raison'] = self::s($c['raison'] ?? null);
        }
        if (isset($champsParType['releve_icar'])) {
            $r = $champsParType['releve_icar'];
            $dossier->setControleMontant(NormalisateurControle::montant($r['montant'] ?? null));
            $extra['releve_icar_nom'] = self::s($r['releve_icar'] ?? null);
            $extra['total_non_lettre'] = NormalisateurControle::montant($r['total_non_lettre'] ?? null);
            $extra['societe'] = self::s($r['societe'] ?? null);
        }
        if (isset($champsParType['petits_comptes'])) {
            $p = $champsParType['petits_comptes'];
            $extra['pc_nom'] = self::s($p['nom'] ?? null);
            $extra['pc_icar'] = self::s($p['icar'] ?? null);
            $extra['pc_montant'] = NormalisateurControle::montant($p['petits_comptes'] ?? null);
            $extra['pc_absent'] = (bool) ($p['absent'] ?? false);
        }

        // Code ICAR CONTROLE = celui LU PAR L'IA sur le justificatif, JAMAIS la saisie
        // secretaire (qui se "validait" toute seule et masquait une absence de justificatif).
        // Le justificatif depend du motif : les petits comptes au trop-percu, la facture
        // d'achat VO au rachat sec. VIDE si l'IA ne l'a pas lu -> ecart visible au controle.
        $sourceIcar = DossierMotif::RACHAT_SEC === $dossier->getMotif() ? 'facture_icar' : 'pc_icar';
        $icar = trim((string) ($extra[$sourceIcar] ?? ''));
        $dossier->setControleIcar('' !== $icar ? $icar : null);

        // Depot PREREMPLI depuis le miroir (compte source) : la COMPTABILITE est la source de
        // verite -> le controle prend le montant NET + l'ICAR (n de compte) + le nom depuis la
        // base (source de verite), sans dependre de l'OCR du releve ICAR (devenu facultatif).
        $sourceCompte = \is_string($extra['source_compte'] ?? null) ? trim((string) $extra['source_compte']) : '';
        if ('' !== $sourceCompte && DossierMotif::TROP_PERCU === $dossier->getMotif()) {
            $tp = $this->eloficash->tropPercu($sourceCompte);
            if ($tp['credit']) {
                $dossier->setControleMontant($tp['montant']);
                $dossier->setControleIcar('' !== $tp['icar'] ? $tp['icar'] : null);
            }
            $co = $this->eloficash->coordonnees($sourceCompte);
            if (null !== $co && '' !== $co['raisonSociale']) {
                $dossier->setControleNom($co['raisonSociale']);
            }
        }

        $dossier->setControleExtra([] !== $extra ? $extra : null);

        [$verdict, $info] = $this->verdict($dossier, $champsParType, '' !== $sourceCompte);
        $dossier->setVerdictIa($verdict);
        $dossier->setVerdictInfo($info);
        $dossier->toucher($par);
    }

    /**
     * @param array<string, array<string, mixed>> $champsParType
     *
     * @return array{0: ?string, 1: ?string} [verdict, info]
     */
    private function verdict(Dossier $dossier, array $champsParType, bool $sourceCompte = false): array
    {
        // Aucune extraction exploitable ET pas de source comptable -> pas de verdict.
        if ([] === $champsParType && !$sourceCompte) {
            return [null, 'Extraction indisponible : verification manuelle requise.'];
        }

        $divergences = [];
        $rib = $champsParType['rib'] ?? null;
        if (null !== $rib) {
            if (NormalisateurControle::iban(self::s($rib['iban'] ?? null)) !== NormalisateurControle::iban($dossier->getIbanClient())) {
                $divergences[] = 'IBAN saisi ≠ IBAN du RIB';
            }
        }

        // Depot prerempli (source comptable) : montant + ICAR CONFIRMES par la base ; on ne
        // verifie plus que l'IBAN (RIB), pas de comparaison avec un releve / des petits comptes.
        if ($sourceCompte) {
            $base = 'Montant et code ICAR confirmés par la comptabilité';
            if ([] === $divergences) {
                return ['valide', $base.(null !== $rib ? ' ; IBAN vérifié sur le RIB.' : ' ; IBAN à vérifier sur le RIB.')];
            }

            return ['invalide', 'À vérifier : '.implode(' ; ', $divergences).' ('.mb_strtolower($base).').'];
        }

        if (DossierMotif::RACHAT_SEC === $dossier->getMotif()) {
            $f = $champsParType['facture_achat_vo'] ?? null;
            if (null !== $f) {
                if (NormalisateurControle::montant($f['montant'] ?? null) !== NormalisateurControle::montant($dossier->getMontant())) {
                    $divergences[] = 'Montant saisi ≠ montant de la facture';
                }
                if (NormalisateurControle::immatriculation(self::s($f['immatriculation'] ?? null)) !== NormalisateurControle::immatriculation($dossier->getImmatriculation())) {
                    $divergences[] = 'Immatriculation saisie ≠ facture';
                }
                // Code client ICAR : comparaison sur les chiffres seuls, l'IA lisant parfois
                // « COMPTANT-805887 » la ou la secretaire saisit « 805887 ». Une lecture VIDE
                // n'est PAS une divergence : l'IA n'a rien trouve, le comptable arbitre.
                $icarFacture = CleDoublon::normaliserIcar(self::s($f['code_icar'] ?? null));
                if ('' !== $icarFacture && $icarFacture !== CleDoublon::normaliserIcar((string) $dossier->getCodeIcar())) {
                    $divergences[] = 'Code client ICAR saisi ≠ facture';
                }
            }
        } else {
            $r = $champsParType['releve_icar'] ?? null;
            if (null !== $r && NormalisateurControle::montant($r['montant'] ?? null) !== NormalisateurControle::montant($dossier->getMontant())) {
                $divergences[] = 'Montant saisi ≠ solde du relevé ICAR';
            }
        }

        if ([] === $divergences) {
            // Libelle de la copie de demonstration : la lecture des pieces y est
            // une lecture locale deterministe, pas une intelligence artificielle
            // executee. Le nommer « IA » induirait le lecteur en erreur.
            return ['valide', 'Contrôles clés concordants entre la saisie et la lecture des pièces.'];
        }

        return ['invalide', 'À vérifier : '.implode(' ; ', $divergences).'.'];
    }

    private static function s(mixed $valeur): string
    {
        return \is_scalar($valeur) ? trim((string) $valeur) : '';
    }
}
