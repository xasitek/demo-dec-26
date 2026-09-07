<?php

declare(strict_types=1);

/**
 * Routeur du serveur local de demonstration.
 *
 *   php -S 127.0.0.1:8123 -t public demo-routeur.php
 *
 * Il sert les fichiers deja compiles (styles, scripts, images) tels quels, et
 * confie tout le reste au controleur frontal de l'application. Rien d'autre :
 * aucune logique, aucune sortie reseau.
 */
$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$fichier = __DIR__.'/public'.urldecode($chemin);

if ('/' !== $chemin && is_file($fichier)) {
    return false; // le serveur integre sert le fichier lui-meme
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__.'/public/index.php';
