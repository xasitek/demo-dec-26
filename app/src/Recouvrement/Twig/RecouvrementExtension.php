<?php

declare(strict_types=1);

namespace App\Recouvrement\Twig;

use App\Recouvrement\Referentiel\Etablissements;
use App\Recouvrement\Repository\RelanceEnvoiRepository;
use App\Recouvrement\Repository\RetourClientRepository;
use App\Recouvrement\Service\FactureSansPdfService;
use App\Recouvrement\Service\MessageCitation;
use App\Recouvrement\Service\SepaQrCode;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Fonctions et filtres Twig du module Recouvrement.
 *
 * `recouvrement_a_traiter()` : nombre de CONVERSATIONS clients non traitées (1 par
 * client, comme la liste des retours — pas le nombre de messages bruts), pour le
 * badge de la nav. Appelée uniquement dans le bloc de menu déjà restreint aux
 * rôles comptable / manager, donc 1 COUNT par page pour ces profils.
 *
 * `message_principal` (filtre) : retire la citation (l'e-mail d'origine recopié)
 * d'une réponse client pour n'afficher que le vrai message.
 *
 * `etablissement` / `etablissement_libelle` (filtres) : traduisent un code
 * établissement (`codeetab`, '093') en libellé lisible ('093 — SYNTHAUTO TEGBRY 51 APV'),
 * depuis le référentiel en mémoire (aucune requête). Un code inconnu reste affiché brut.
 */
final class RecouvrementExtension extends AbstractExtension
{
    public function __construct(
        private readonly RetourClientRepository $retours,
        private readonly RelanceEnvoiRepository $relances,
        private readonly FactureSansPdfService $factures,
        private readonly CacheInterface $cache,
        private readonly SepaQrCode $sepaQr,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('recouvrement_a_traiter', $this->aTraiter(...)),
            new TwigFunction('recouvrement_courriers_a_envoyer', $this->courriersAEnvoyer(...)),
            new TwigFunction('recouvrement_factures_sans_pdf', $this->nbFacturesSansPdf(...)),
            new TwigFunction('sepa_qr', $this->qrSepa(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('message_principal', MessageCitation::principal(...)),
            new TwigFilter('etablissement', Etablissements::avecCode(...)),
            new TwigFilter('etablissement_libelle', Etablissements::libelle(...)),
        ];
    }

    public function aTraiter(): int
    {
        // Conversations (regroupées par client), STRICTEMENT cohérent avec la liste
        // des retours : on force 'rattaches' comme le contrôleur, pour que le badge
        // ne compte JAMAIS les orphelins (masqués de la liste) — sinon un compteur
        // fantôme que la comptable ne peut pas vider (rien à cliquer).
        return $this->retours->compterConversations(null, null, null, 'rattaches');
    }

    public function courriersAEnvoyer(): int
    {
        return $this->relances->compterCourriersEnAttente();
    }

    /**
     * PNG data-URI d'un QR virement SEPA (EPC069-12) pour un etablissement, ou
     * chaine vide si donnees insuffisantes. Appele par etablissement dans le releve.
     */
    public function qrSepa(?string $iban, ?string $bic, string $beneficiaire, string $montant, string $reference): string
    {
        return $this->sepaQr->dataUri($iban, $bic, $beneficiaire, $montant, $reference);
    }

    public function nbFacturesSansPdf(): int
    {
        // Compteur du badge de nav (présent sur toutes les pages du module) : le COUNT
        // joint plusieurs tables -> cache court pour ne pas le refaire à chaque page.
        return $this->cache->get('recouvrement_nb_factures_sans_pdf', function (ItemInterface $item): int {
            $item->expiresAfter(60);

            return $this->factures->compter();
        });
    }
}
