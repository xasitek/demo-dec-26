<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Fusionne + compresse plusieurs PDF de facture en UN seul (Ghostscript), puis
 * deduplique les pages identiques.
 *
 * Contexte Synthauto : Progiciel colle une page de conditions generales (CGV, une IMAGE)
 * apres chaque page de facture. Un compte a 72 factures produit donc ~148 pages
 * dont la moitie sont la meme CGV, et le mail depasse la limite SMTP (552).
 *
 * Traitement :
 *   1. fusion + compression (preset /ebook, 150 dpi) : dedoublonne deja les
 *      ressources partagees (l'image CGV n'est stockee qu'une fois) ;
 *   2. deduplication des PAGES identiques : on rend chaque page en petite
 *      empreinte et on ne garde que la 1re occurrence de chaque empreinte. Les
 *      pages de facture sont toutes differentes (jamais retirees) ; seule la CGV,
 *      repetee a l'identique, est reduite a un exemplaire.
 *
 * Securite : une page n'est retiree QUE si une page rigoureusement identique
 * existe deja en amont -> aucune page de facture (unique) ne peut etre perdue.
 *
 * Degradation gracieuse : binaire Ghostscript absent (poste de dev, hebergement
 * sans gs) ou echec -> retourne null ; l'appelant se replie (attacher
 * individuellement, ou envoyer sans piece jointe).
 */
final class FusionPdfService
{
    /** Preset Ghostscript : 150 dpi, bon compromis lisibilite/poids pour des factures. */
    private const PRESET = '/ebook';

    /** Resolution de rendu pour l'empreinte de deduplication (assez pour distinguer les factures). */
    private const DEDUP_DPI = 72;

    private const TIMEOUT_SECONDES = 180;

    public function __construct(
        private readonly string $ghostscriptBin,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fusionne + compresse + deduplique les PDF fournis en un seul PDF.
     *
     * @param list<string> $pdfs contenus binaires des PDF (chacun commence par %PDF)
     *
     * @return string|null le PDF final, ou null si indisponible / echec / entree vide
     */
    public function fusionner(array $pdfs): ?string
    {
        $binaire = trim($this->ghostscriptBin);
        if ('' === $binaire || [] === $pdfs) {
            return null;
        }

        $repertoire = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'recouvrement_pdf_'.bin2hex(random_bytes(8));
        if (!@mkdir($repertoire, 0o700, true) && !is_dir($repertoire)) {
            $this->logger->warning('Recouvrement : repertoire temporaire de fusion PDF impossible', ['repertoire' => $repertoire]);

            return null;
        }

        try {
            $entrees = [];
            foreach ($pdfs as $index => $contenu) {
                $chemin = sprintf('%s%s%03d.pdf', $repertoire, \DIRECTORY_SEPARATOR, $index + 1);
                if (false === @file_put_contents($chemin, $contenu)) {
                    return null;
                }
                $entrees[] = $chemin;
            }

            // 1. Fusion + compression. La liste des fichiers d'entree est passee a
            // Ghostscript via un fichier @reponse (et NON en arguments inline) : sur
            // Windows, quelques centaines de chemins depassent la limite de longueur de
            // ligne de commande (~8 Ko via cmd.exe) et gs echoue (code 1, sans message).
            // Le fichier @liste garde une commande courte quel que soit le nombre de
            // factures. Slash avant (accepte par gswin) pour eviter toute interpretation
            // du backslash dans le contenu du fichier @.
            $merged = $repertoire.\DIRECTORY_SEPARATOR.'merged.pdf';
            $liste = $repertoire.\DIRECTORY_SEPARATOR.'entrees.txt';
            $lignes = array_map(static fn (string $c): string => str_replace('\\', '/', $c), $entrees);
            if (false === @file_put_contents($liste, implode("\n", $lignes)."\n")) {
                return null;
            }
            if (!$this->executerGs($binaire, array_merge(self::argsPdfwrite($merged), ['@'.$liste])) || !is_file($merged)) {
                return null;
            }

            // 2. Deduplication des pages identiques (CGV repetees).
            $final = $this->dedupliquerPages($binaire, $repertoire, $merged);

            $contenu = @file_get_contents($final);
            if (false === $contenu || !str_starts_with($contenu, '%PDF')) {
                return null;
            }

            return $contenu;
        } catch (Throwable $e) {
            $this->logger->warning('Recouvrement : fusion PDF impossible', ['erreur' => $e->getMessage()]);

            return null;
        } finally {
            $this->nettoyer($repertoire);
        }
    }

    /**
     * Retire les pages strictement identiques (la CGV repetee), en gardant la 1re
     * occurrence de chaque page. Retourne le chemin du PDF dedoublonne, ou le PDF
     * fusionne d'origine si aucun doublon / rendu impossible.
     */
    private function dedupliquerPages(string $binaire, string $repertoire, string $merged): string
    {
        $motif = $repertoire.\DIRECTORY_SEPARATOR.'pg_%d.png';
        if (!$this->executerGs($binaire, ['-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pnggray', '-r'.self::DEDUP_DPI, '-sOutputFile='.$motif, $merged])) {
            return $merged;
        }

        $vues = [];
        $garder = [];
        $total = 0;
        while (is_file($repertoire.\DIRECTORY_SEPARATOR.'pg_'.($total + 1).'.png')) {
            ++$total;
            $empreinte = md5_file($repertoire.\DIRECTORY_SEPARATOR.'pg_'.$total.'.png');

            // On garde la page si son empreinte est nouvelle (ou incalculable :
            // dans le doute on ne supprime jamais).
            if (false === $empreinte || !isset($vues[$empreinte])) {
                if (false !== $empreinte) {
                    $vues[$empreinte] = true;
                }
                $garder[] = $total;
            }
        }

        // Aucune page rendue, ou aucun doublon : on garde la fusion telle quelle.
        if (0 === $total || \count($garder) === $total) {
            return $merged;
        }

        $dedup = $repertoire.\DIRECTORY_SEPARATOR.'dedup.pdf';
        if (!$this->executerGs($binaire, array_merge(self::argsPdfwrite($dedup), ['-sPageList='.implode(',', $garder), $merged])) || !is_file($dedup)) {
            return $merged;
        }

        $this->logger->info('Recouvrement : pages dupliquees (CGV) retirees du PDF de relance', [
            'pages_avant' => $total,
            'pages_apres' => \count($garder),
        ]);

        return $dedup;
    }

    /**
     * Execute Ghostscript avec les arguments fournis. Journalise et retourne false en cas d'echec.
     *
     * @param list<string> $args
     */
    private function executerGs(string $binaire, array $args): bool
    {
        $process = new Process(array_merge([$binaire], $args));
        $process->setTimeout(self::TIMEOUT_SECONDES);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning('Recouvrement : commande Ghostscript en echec', [
                'code' => $process->getExitCode(),
                'erreur' => mb_substr($process->getErrorOutput(), 0, 500),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Arguments pdfwrite communs (compression /ebook) vers le fichier de sortie donne.
     *
     * @return list<string>
     */
    private static function argsPdfwrite(string $sortie): array
    {
        return [
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-sDEVICE=pdfwrite',
            '-dPDFSETTINGS='.self::PRESET,
            '-dCompatibilityLevel=1.5',
            '-sOutputFile='.$sortie,
        ];
    }

    /**
     * Supprime le repertoire temporaire et son contenu (best-effort).
     */
    private function nettoyer(string $repertoire): void
    {
        foreach (glob($repertoire.\DIRECTORY_SEPARATOR.'*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($repertoire);
    }
}
