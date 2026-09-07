<?php

declare(strict_types=1);

namespace App\Creances\Service;

use App\Creances\Entity\CompteStrategie;
use App\Creances\Entity\RelanceEnvoi;
use App\Creances\Entity\StrategieNiveau;
use App\Creances\Enum\RelanceStatut;
use App\Creances\Enum\RelanceVecteur;
use App\Creances\Repository\ActionRepository;
use App\Creances\Repository\CompteStrategieRepository;
use App\Creances\Repository\CreancesRepository;
use App\Creances\Repository\ModeleCourrierRepository;
use App\Creances\Repository\RelanceEnvoiRepository;
use App\Shared\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;

/**
 * Orchestre la Relance Expert : selection des comptes a relancer, rendu des
 * modeles de courrier, envoi email via Symfony Mailer et journalisation.
 *
 * Limitations V1 :
 *  - Pas de sandbox Twig (les modeles sont edites par des comptables
 *    internes, risque limite). Le sandbox sera ajoute si on ouvre l'edition
 *    a un public plus large.
 *  - Envoi synchrone : Symfony Mailer en mode direct. Pour scaler a des
 *    centaines de comptes, on basculera sur Messenger (deja installe).
 *  - Pas de PDF : le vecteur "courrier" produit un HTML imprimable
 *    consultable dans le journal des envois ; l'impression batch suivra
 *    quand dompdf/mpdf sera autorise.
 */
final class RelanceExpertService
{
    public function __construct(
        private readonly CompteStrategieRepository $compteStrategies,
        private readonly RelanceEnvoiRepository $envois,
        private readonly ModeleCourrierRepository $modeles,
        private readonly CreancesRepository $creances,
        private readonly ActionRepository $actions,
        private readonly Environment $twig,
        private readonly MailerInterface $mailer,
        private readonly RecouvrementNotifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly string $expediteur = 'recouvrement@demonstration.invalid',
    ) {
    }

    /**
     * Prepare un envoi en attente pour un compte a partir de sa strategie et
     * du niveau courant + 1. Renvoie null si le compte n'a pas de strategie
     * ou si le niveau cible n'existe pas (fin de strategie).
     */
    public function preparerProchaineRelance(CompteStrategie $cs, ?User $auteur): ?RelanceEnvoi
    {
        $strategie = $cs->getStrategie();
        if (null === $strategie) {
            return null;
        }
        $prochainOrdre = $cs->getNiveauCourant() + 1;
        $niveau = null;
        foreach ($strategie->getNiveaux() as $n) {
            if ($n->getOrdre() === $prochainOrdre) {
                $niveau = $n;
                break;
            }
        }
        if (null === $niveau) {
            return null;
        }

        return $this->preparerPourNiveau($cs->getCompteCode(), $niveau, $auteur);
    }

    /**
     * Niveau de relance auto-calcule selon le NOMBRE D'ACTIONS deja menees
     * sur le compte (toutes sources confondues : appels manuels, emails,
     * courriers, relances de strategie). Plus le compte a deja recu d'actions,
     * plus on monte dans le workflow de relance. Plafonne au dernier niveau
     * de la strategie.
     *
     * Mapping :
     *  - 0 action  → niveau d'ordre 1 (premiere relance, soft)
     *  - n actions → niveau d'ordre min(n + 1, ordre_max_strategie)
     */
    public function preparerNiveauAutoPourCompte(CompteStrategie $cs, ?User $auteur): ?RelanceEnvoi
    {
        $strategie = $cs->getStrategie();
        if (null === $strategie) {
            return null;
        }
        $niveaux = $strategie->getNiveaux();
        if (0 === count($niveaux)) {
            return null;
        }

        // Compte les actions deja menees pour ce compte.
        $nbActions = count($this->actions->findByCompte($cs->getCompteCode()));

        // Ordre max disponible dans la strategie.
        $ordreMax = 0;
        foreach ($niveaux as $n) {
            if ($n->getOrdre() > $ordreMax) {
                $ordreMax = $n->getOrdre();
            }
        }
        if (0 === $ordreMax) {
            return null;
        }

        $ordreCible = min($nbActions + 1, $ordreMax);
        $niveau = null;
        foreach ($niveaux as $n) {
            if ($n->getOrdre() === $ordreCible) {
                $niveau = $n;
                break;
            }
        }
        if (null === $niveau) {
            // Fallback : prend le dernier niveau disponible.
            foreach ($niveaux as $n) {
                if ($n->getOrdre() === $ordreMax) {
                    $niveau = $n;
                    break;
                }
            }
        }
        if (null === $niveau) {
            return null;
        }

        return $this->preparerPourNiveau($cs->getCompteCode(), $niveau, $auteur);
    }

    /**
     * Prepare un envoi pour un niveau donne (utile aussi pour les envois
     * "ponctuels" depuis un bouton).
     */
    public function preparerPourNiveau(string $compteCode, StrategieNiveau $niveau, ?User $auteur): RelanceEnvoi
    {
        $modeleId = $niveau->getModeleCourrierId();
        $modele = null;
        if (null !== $modeleId) {
            $modele = $this->modeles->find($modeleId);
        }

        $contexte = $this->construireContexte($compteCode);
        $corpsRendu = null;
        $sujetRendu = null;
        if (null !== $modele) {
            $corpsRendu = $this->renduTwig($modele->getCorpsHtml(), $contexte);
            $sujetRendu = null !== $modele->getSujet()
                ? $this->renduTwig($modele->getSujet(), $contexte)
                : 'Relance Groupe Synthauto - compte '.$compteCode;
        }

        $vecteur = match ($niveau->getTypeAction()->value) {
            'email' => RelanceVecteur::Email,
            'courrier' => RelanceVecteur::Courrier,
            'sms' => RelanceVecteur::Sms,
            default => RelanceVecteur::Email,
        };

        $tiers = $contexte['tiers'];
        /** @var array<string, mixed> $tiers */
        $destinataire = null;
        if (RelanceVecteur::Email === $vecteur && isset($tiers['email']) && is_string($tiers['email'])) {
            $destinataire = $tiers['email'];
        } elseif (RelanceVecteur::Sms === $vecteur && isset($tiers['telephone']) && is_string($tiers['telephone']) && '' !== $tiers['telephone']) {
            $destinataire = $tiers['telephone'];
        } elseif (RelanceVecteur::Courrier === $vecteur && isset($tiers['adresse_complete']) && is_string($tiers['adresse_complete']) && '' !== $tiers['adresse_complete']) {
            $destinataire = $tiers['adresse_complete'];
        }

        $envoi = new RelanceEnvoi(
            $compteCode,
            $vecteur,
            $auteur,
            $modele,
            $niveau,
            null,
            $destinataire,
            $sujetRendu,
            $corpsRendu,
        );
        $this->envois->save($envoi);

        return $envoi;
    }

    /**
     * Envoie effectivement un RelanceEnvoi (uniquement pour le vecteur
     * Email pour l'instant). Met a jour le statut et le message d'erreur.
     */
    public function envoyer(RelanceEnvoi $envoi): bool
    {
        if (RelanceStatut::AEnvoyer !== $envoi->getStatut()) {
            return false;
        }

        if (RelanceVecteur::Email !== $envoi->getVecteur()) {
            $envoi->marquerErreur('Vecteur non gere en V1 (seul l email est envoye). Voir la fiche pour traiter manuellement.');
            $this->envois->save($envoi);

            return false;
        }

        $destinataire = $envoi->getDestinataire();
        if (null === $destinataire || '' === $destinataire || !filter_var($destinataire, \FILTER_VALIDATE_EMAIL)) {
            $envoi->marquerErreur('Adresse e-mail destinataire invalide ou absente.');
            $this->envois->save($envoi);

            return false;
        }

        try {
            $email = (new Email())
                ->from($this->expediteur)
                ->to($destinataire)
                ->subject($envoi->getSujet() ?? 'Relance Groupe Synthauto')
                ->html($envoi->getCorpsHtml() ?? '<p>(corps vide)</p>');

            $this->mailer->send($email);
            $envoi->marquerEnvoye();
            $this->envois->save($envoi);

            $cs = $this->compteStrategies->findByCompte($envoi->getCompteCode());
            if (null !== $cs && null !== $envoi->getStrategieNiveau()) {
                $cs->enregistrerRelance($envoi->getStrategieNiveau()->getOrdre());
                $this->compteStrategies->save($cs);
            }
            $this->notifier->notifierTiers($envoi->getCompteCode(), 'relance_envoyee', ['envoi_id' => $envoi->getId()]);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Echec envoi relance {id} : {message}', [
                'id' => $envoi->getId(),
                'message' => $e->getMessage(),
            ]);
            $envoi->marquerErreur('Erreur SMTP : '.$e->getMessage());
            $this->envois->save($envoi);

            $cs = $this->compteStrategies->findByCompte($envoi->getCompteCode());
            if (null !== $cs) {
                $cs->signalerErreur('Envoi en erreur : '.$e->getMessage());
                $this->compteStrategies->save($cs);
            }

            return false;
        }
    }

    /**
     * Construit le contexte Twig (tiers + ecritures + agregats) pour le
     * rendu d'un modele de courrier.
     *
     * @return array<string, mixed>
     */
    private function construireContexte(string $compteCode): array
    {
        $tiers = $this->creances->tiers($compteCode);
        $d = [];
        if (null !== $tiers) {
            $brut = $tiers['donnees'] ?? null;
            $d = is_array($brut) ? $brut : (is_string($brut) ? (json_decode($brut, true) ?: []) : []);
        }
        $creances = $this->creances->creancesOuvertesDuTiers($compteCode);
        $ecritures = [];
        $montantTotal = 0.0;
        foreach ($creances as $row) {
            $ee = is_array($row['donnees']) ? $row['donnees'] : (is_string($row['donnees']) ? (json_decode($row['donnees'], true) ?: []) : []);
            $montant = (float) ($ee['Montant (valeur absolue)'] ?? 0);
            $montantTotal += $montant;
            $ecritures[] = [
                'numpiece' => (string) ($ee['numpiece'] ?? ''),
                'date' => isset($ee['dateecriture']) && is_string($ee['dateecriture'])
                    ? substr($ee['dateecriture'], 0, 10)
                    : '',
                'montant' => $montant,
                'retard' => (string) ($ee['retard'] ?? ''),
            ];
        }

        $adresseComplete = trim(
            (string) ($d['Adresse 1'] ?? $d['adresse'] ?? '')
            .' '.(string) ($d['Code postal'] ?? '')
            .' '.(string) ($d['Ville'] ?? '')
        );

        return [
            'tiers' => [
                'civilite' => (string) ($d['civilite'] ?? ''),
                'prenom' => (string) ($d['prenom'] ?? ''),
                'nom' => (string) ($d['nom'] ?? ''),
                'adresse' => (string) ($d['Adresse 1'] ?? $d['adresse'] ?? ''),
                'code_postal' => (string) ($d['Code postal'] ?? ''),
                'ville' => (string) ($d['Ville'] ?? ''),
                'adresse_complete' => $adresseComplete,
                'email' => isset($d['email']) && is_string($d['email']) ? $d['email'] : null,
                'telephone' => (string) ($d['Téléphone'] ?? $d['numtel'] ?? $d['numtel2'] ?? ''),
            ],
            'compte_code' => $compteCode,
            'montant_total' => $montantTotal,
            'date_aujourdhui' => date('d/m/Y'),
            'ecritures' => $ecritures,
            'signature' => 'Service Comptabilite Groupe Synthauto',
        ];
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function renduTwig(string $template, array $contexte): string
    {
        try {
            $t = $this->twig->createTemplate($template);

            return $t->render($contexte);
        } catch (Throwable $e) {
            $this->logger->warning('Rendu modele courrier echoue : {message}', ['message' => $e->getMessage()]);

            return '<p>Erreur de rendu du modele : '.htmlspecialchars($e->getMessage(), \ENT_QUOTES, 'UTF-8').'</p>';
        }
    }
}
