# CLAUDE.md — Module Espace Livraison

Ce fichier complète le `CLAUDE.md` racine, qui s'applique aussi. En cas de
contradiction, les règles ci-dessous l'emportent.

Cadrage complet : `docs/MODULE_LIVRAISON.md`. Le lire avant toute décision de
conception — il contient la cartographie du circuit remplacé et les pièges déjà
identifiés.

## Le module en une phrase

Transmettre aux loueurs (LOUEUR A, LOUEUR B, LOUEUR C...) le dossier complet d'un
véhicule livré — PV de livraison, bon de commande, CPI, plus les factures — sans
quoi le loueur ne paie pas.

## Périmètre

**Modifiable** : `src/Livraison/**`, `templates/livraison/**`, les migrations qui
ne touchent que le schéma `livraison`, `docs/MODULE_LIVRAISON.md`.

**Interdit sans accord explicite** : tout le reste, en particulier `src/Shared/**`,
les autres modules, `config/packages/**`, et **toute migration touchant
`mirror.*`**. Le miroir est alimenté par l'extraction Eloficash et lu par Créances
et Recouvrement : un index ou une contrainte posée là impacte trois modules.

## Les pièges du miroir — à connaître avant d'écrire une requête

Vérifiés sur données réelles le 2026-09-01. Ils ont déjà produit un filtre faux.

1. **Les montants sont du texte.** `solde` vaut `0.00`, pas `0`. Toute comparaison
   numérique exige un cast, et le cast doit être gardé par une regex, sinon une
   seule ligne sale fait échouer la vue entière.
2. **`Montant solde en devise entité` est formaté à la française** (`-14 913,76 €`,
   espaces insécables) et n'est pas castable. Utiliser `solde`.
3. **`lettree` vaut `'0'` sur les 41 560 lignes** du compte 4111000. Le champ est
   constant : il ne filtre rien.
4. **Le loueur est `Code payeur`**, jamais `financeur` (renseigné sur 77 lignes
   sur 465). Les codes vivent dans `livraison.loueur`.
5. **Soft-delete** : `present_dans_sage = false` signifie que l'écriture a disparu
   de Sage. Ne jamais l'oublier dans un filtre, ni poser de clé étrangère physique
   vers `mirror.*`.
6. **Pas de plafond arbitraire.** Le tableur limitait à 6 factures par véhicule ;
   37 immatriculations dépassent ce seuil. Aucune requête du module ne doit
   réintroduire une limite de ce genre.
7. **`numimmat` n'est pas toujours une plaque.** Sur la file de travail : 508
   lignes portent une plaque SIV (`AB-123-CD`), 8 portent autre chose — un numéro
   de série ou de commande, pour des véhicules pas encore immatriculés au moment
   de la facturation. Ne jamais parser une plaque par découpe de position : le
   tableur le faisait avec `RIGHT(immat; 9)` et produisait du n'importe quoi sur
   ces cas. Valider par expression régulière, et prévoir le véhicule sans plaque.

## Conventions

- Les vues du module exposent des **alias propres**. Aucun `donnees->>'...'` hors
  d'une définition de vue — même convention que `creances.v_creances_ouvertes`.
- Rien de dérivable n'est stocké : le module ne persiste que l'acte humain
  (déclaration, pièces, arbitrage) et l'état du processus.
- Pas de logique métier dans les contrôleurs : services dédiés, handlers Messenger.
- Le contrôle de conformité est **toujours** asynchrone : il appelle un modèle de
  langage sur plusieurs PDF, jamais dans une requête HTTP.
- Cloisonnement par établissement : une secrétaire ne voit que sa concession.
  Deny-by-default, comme partout dans le projet.
- Zéro emoji. Le circuit remplacé utilisait des ronds verts et rouges comme
  indicateurs d'état ; le module utilise un composant conforme à la charte.

## Avant commit

```
vendor/bin/php-cs-fixer fix src/Livraison --config=.php-cs-fixer.dist.php
vendor/bin/phpstan analyse src/Livraison
```

Ne jamais lancer php-cs-fixer sur tout le dépôt : il reformate des fichiers hors
sujet.

## Base de données

`DATABASE_URL` pointe sur **Render, c'est-à-dire la production**. Une commande de
lecture ne pose pas de problème ; une migration en pose un. Toujours demander avant
de lancer `doctrine:migrations:migrate`.

## État actuel

Phase 1 du plan de migration : lecture seule. Livré à ce jour —
`livraison.loueur`, la vue `livraison.v_a_livrer`, et la commande
`app:livraison:parite` qui compare cette vue à l'onglet `ESPACE LIVREUR` du
classeur Google.

**Parité mesurée : 93,9 %** (447 écritures communes sur 476). Les 100 écritures que
la vue a en plus sont normales — elle est vivante, le Sheet n'est qu'une photo
rafraîchie par cron. Les 29 manquantes sont des ventes au comptant, en attente d'un
arbitrage métier (question ouverte n°10 du cadrage).

Livré ensuite :

- **Poste secrétaire à espaces** (`src/Shared/Secretaire`) : un module déclare son
  espace, la barre latérale et le confinement le découvrent seuls. Accueil neutre
  à `/espace`.
- **Écran de déclaration** (`/livraison/declarer`) : la liste des véhicules de son
  établissement vient de la vue, sélection multiple, trois pièces. Les factures ne
  sont jamais demandées.
- **Écran comptable** (`/livraison/controle`) : file par statut, dossier avec ses
  pièces en aperçu et ses factures relues dans la comptabilité, arbitrage
  conforme / anomalie, horodaté et attribué.

Non branché à ce jour : le contrôle automatique des pièces (12 règles, modèle de
langage) et l'envoi au loueur. La comptable arbitre seule, comme aujourd'hui.

**Le chemin d'écriture n'est pas encore vérifié de bout en bout** : il faut être
connectée en `ROLE_SECRETAIRE`, et il n'existe pas de base de test (`.env.test`
n'a pas de `DATABASE_URL`, un test fonctionnel écrirait donc en production). Les
règles d'acceptation des pièces, elles, sont couvertes par 19 tests unitaires.

## Un piège du poste, pas du code

`.env.local` force `APP_ENV=prod`, et `var/cache/prod` appartient à SYSTEM sur cette
machine : le CLI ne peut donc pas recompiler le conteneur, et une commande
nouvellement créée reste invisible. Lancer les commandes du module en dev :

```
APP_ENV=dev php bin/console app:livraison:parite
```

Les migrations, elles, passent en prod : elles n'ont pas besoin d'un conteneur neuf.
