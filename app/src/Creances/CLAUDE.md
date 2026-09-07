# CLAUDE.md — Module Créances (recouvrement)

Tu lis ce fichier parce que tu aides un dev (pseudonyme retire de la copie) à coder le **module Créances** d'Finance Créances. Ce fichier complète le `CLAUDE.md` racine (qui s'applique aussi). En cas de contradiction, **les règles ci-dessous l'emportent**.

## Périmètre — ce que tu PEUX modifier

- `src/Creances/**` (tout ce qui est dans ce dossier)
- `templates/creances/**`
- Les migrations Doctrine qui **ne touchent QUE le schéma `creances`** (PostgreSQL — schéma déjà créé, vide)
- `docs/MODULE_CREANCES.md` (la doc du module)

## Périmètre — ce que tu NE TOUCHES JAMAIS sans accord explicite de la responsable technique

Tout le reste, en particulier :

- `src/Shared/**`, `src/Garanties/**`, autres modules
- `config/packages/**` (security.yaml, doctrine.yaml, framework.yaml, monolog.yaml, etc.)
- `config/services.yaml`, `config/routes/**`
- `templates/layout.html.twig`, `templates/_ui.html.twig`, `templates/base.html.twig`
- `assets/styles/app.css` (utilise les classes existantes : `.fc-table-comptable`, `.fc-select`, `.fc-multi`, `.fc-filter-pills`, etc.)
- `assets/controllers/*` autres que ceux que tu créerais dans ton module
- Les migrations qui toucheraient `mirror.*`, `shared.*`, `garanties.*`
- `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `composer.json`, `package.json`

**Si tu penses avoir besoin de toucher à un de ces fichiers** (ex. ajouter une entrée dans la sidebar, modifier un rôle, ajouter une dépendance), **STOP et dis à le developpeur** :

> « Cette modif touche à la structure/config globale. Pose la question à la responsable technique d'abord — sur Slack ou en personne. Je n'avance pas tant qu'elle a pas validé. »

## Git — discipline absolue

1. **le developpeur travaille TOUJOURS sur une branche dédiée**, jamais sur `master`. La branche est créée dès le début et reste jusqu'à la complétion totale du module.
   - Si la branche n'existe pas : `git checkout -b feature/creances` (ou un nom plus précis selon le sous-chantier).
   - Si tu es déjà sur master au démarrage d'une session, demande à le developpeur : « Tu es sur master, je crée la branche feature/creances ? ».
2. **Tu ne proposes JAMAIS `git push`.** le developpeur ne connaît pas la commande, et le push se fait uniquement avec la responsable technique présente. Si le developpeur le demande, réponds :
   > « Le push, c'est la responsable technique qui le fait avec toi. Continue de bosser sur ta branche, commit local autant que tu veux. »
3. Commits locaux : oui, autant que nécessaire. Messages clairs en français, sans emojis.
4. **La branche reste ouverte jusqu'à ce que TOUT le module soit fini.** Pas de merge intermédiaire vers master.

## Conventions techniques (héritées du projet)

- **PHP 8.3 + Symfony 7.4 LTS**, Twig, AssetMapper (pas de Webpack), TailwindCSS 4 via `tailwind-bundle`.
- Toujours `declare(strict_types=1);` en haut des fichiers PHP.
- **PHPStan niveau 8** : `vendor/bin/phpstan analyse src/Creances` doit passer.
- **PHP-CS-Fixer** : `PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix` avant chaque commit.
- **Aucun emoji** dans le code, les commits, l'UI, la doc.
- **Tout en français** (commentaires, libellés UI, messages d'erreur, doc).
- **Accents français corrects** dans toute chaîne visible à l'utilisateur.
- Pas de logique métier dans les contrôleurs — services dans `src/Creances/Service/`.
- Test PHPUnit/Pest pour la logique métier (workflow, calculs).

## UI — composants réutilisables (utilise-les, n'invente pas)

Tu disposes déjà des composants stylisés SYNTHAUTO, à utiliser tels quels :

- **Tableau dense comptable** : classe CSS `.fc-table-comptable` (lignes serrées, grille, zébrage, monospace pour les nombres). Voir l'écran Garanties pour l'exemple.
- **Select simple** : macro Twig `ui.fc_select(name, options, selected)` dans `templates/_ui.html.twig`.
- **Multi-select** : macro `ui.fc_multi_select(name, options, selected, placeholder, count_label)` (checkbox dropdown auto-submit).
- **Pills de filtres actifs** : classes `.fc-filter-pills`, `.fc-filter-pill`. Controller Stimulus `filter-pills`.
- **Scroll infini** : controller Stimulus `infinite-scroll` (route avec `?fragment=1` qui renvoie le partial des lignes).
- **Sections étiquetées par source** : `.fc-filter-section.is-source-*` (gold/navy/neutral).
- **Panneau de détail slide-in** : controller Stimulus `detail-panel`. Pattern utilisé dans Garanties.

Pour les filtres et les pages, **copie la structure de `templates/garanties/`** comme modèle — c'est la référence.

## Données du domaine — tu lis via les vues `creances.v_*`, JAMAIS `mirror.*`

Trois schémas se partagent les données :

- `mirror.*` (lecture seule, copie quotidienne de Sage par l'ETL) : tu ne le lis ni ne l'écris JAMAIS directement depuis le module. Voir le point 2 plus bas.
- `creances_demo.*` (créé pour le seed local — `creances_demo.tiers`, `creances_demo.balance_agee`, `creances_demo.bal_eloficash`) : alimenté par `bin/console app:creances:seed-demo --confirm` pour avoir des données en dev sans dump Sage. Structure identique à `mirror.*`.
- `creances.v_*` : **les vues que tu lis depuis le code**. Chacune est un `UNION ALL` entre `mirror.X` et `creances_demo.X`. Trois vues disponibles :
  - `creances.v_balance_agee` (~1 000 lignes Sage) : balance âgée des tiers (créances classées par âge).
  - `creances.v_tiers` (~15 000 lignes Sage) : référentiel clients/fournisseurs.
  - `creances.v_bal_eloficash` (~100 000 lignes Sage) : toutes les écritures comptables (créance, paiement, etc.).

Toutes ces vues exposent les colonnes : `cle`, `donnees` (JSONB de la ligne Sage brute), `content_hash`, `present_dans_sage`, `cree_le`/`vu_le`/`modifie_le`. Pour exposer un champ JSONB spécifique, fais `donnees->>'nomColonne'`.

**Pourquoi des vues plutôt que `mirror.*` direct** : `mirror.*` est strictement réservé à l'ETL Sage (lecture seule applicative). Le seed local doit aller dans `creances_demo.*` sans polluer le miroir. Les vues unifient les deux sources pour que ton code ait une seule cible logique.

Détails et exemples concrets dans `docs/MODULE_CREANCES.md`.

## Réflexes à imposer à le developpeur (s'il oublie, c'est à toi de les remonter)

Le projet a 3 piliers non négociables. Si le developpeur code quelque chose qui les ignore, **interromps-le** et propose une variante alignée. **Ne lui laisse pas livrer sans avoir réfléchi à ces 3 angles.**

1. **Temps réel** — voir [docs/REALTIME.md](../../docs/REALTIME.md).
   - Toute donnée qui peut évoluer côté serveur (nouvelle relance, paiement encaissé, statut changé, etc.) doit être **poussée aux clients ouverts** via Mercure + Turbo Streams. Pas de F5 manuel.
   - Mutualiser la connexion Mercure : **une seule** par onglet, multi-topics. Ne pas créer une nouvelle `EventSource` par composant.
   - Vois le pattern réel dans le module Garanties (controller Stimulus `realtime` qui multiplexe).

2. **Performance / scalabilité** — voir [docs/PERFORMANCE.md](../../docs/PERFORMANCE.md).
   - Toujours penser « et si × 100 ? ». Une balance âgée à 1 000 lignes aujourd'hui peut faire 100 000 dans 2 ans.
   - **Zéro N+1** : eager loading explicite ou requête DBAL ciblée.
   - **Pagination / scroll infini** sur toute liste qui peut dépasser ~50 lignes (utilise le Stimulus `infinite-scroll`).
   - **Index DB** sur les colonnes filtrées/triées dès la migration, pas après.
   - **Cache** (Redis disponible) pour les agrégats coûteux et stables.
   - **Async via Messenger** pour tout ce qui peut prendre > 1s (génération de PDF, envoi d'emails en masse, etc.).

3. **Sécurité** — voir [docs/SECURITY.md](../../docs/SECURITY.md).
   - **Deny-by-default** : chaque route a son `#[IsGranted(ROLE_...)]`. Le module Créances cible **ROLE_COMPTABLE** ; ne mets pas plus large sans demander.
   - **CSRF** sur tout formulaire qui modifie un état (token via `csrf_token('...')` dans Twig, vérifié dans le contrôleur).
   - **Aucune donnée sensible (téléphone, email, montant) dans les logs** sauf nécessité absolue. Anonymise.
   - **Validation stricte** des entrées utilisateur (types, longueurs, format) avant tout passage à la DB.
   - **Pas de SQL concaténé** : utilise Doctrine ORM ou DBAL avec paramètres nommés / positionnels.
   - **Pas d'écriture dans `mirror.*`** (lecture seule, copie de Sage). Pas non plus de **lecture directe** : passe toujours par les vues `creances.v_*`. Si tu as besoin de seed/fake data, écris dans `creances_demo.*` (jamais dans `mirror.*`, même en migration Doctrine "ponctuelle").

À chaque fonctionnalité non triviale, demande-toi : « est-ce que j'ai un signal temps réel à émettre ? Mon code tient à 100× le volume ? Cette route est-elle bien restreinte au bon rôle ? ». Si non, ajuste avant de commit.

## Avant d'installer un package / un bundle / une lib

**Tu vérifies TOI-MÊME si ce qu'il faut existe déjà dans le projet, avant de proposer quoi que ce soit à le developpeur.** Le pattern :

1. **Composer (PHP)** : lis `composer.json` (et au besoin `composer.lock`). Si la dépendance est déjà là (directement ou transitivement), utilise-la directement. Beaucoup de choses sont déjà installées (mailer, twig-extra, doctrine, symfony/ux-turbo, knpuniversity/oauth2-client-bundle, etc.).
2. **JS/Stimulus** : regarde `assets/controllers/*.js`, `assets/controllers.json`, et `importmap.php` à la racine.
3. **CSS** : regarde `assets/styles/app.css` — les composants SYNTHAUTO standards y sont (`fc-table-comptable`, `fc-multi`, `fc-filter-pills`…).

**Si le package n'est PAS dans le projet** : ne propose JAMAIS la commande `composer require` ou équivalente. À la place, dis à le developpeur :

> « Ce paquet (X) n'est pas dans le projet. Demande à la responsable technique si on peut l'ajouter — ça touche au socle, c'est sa décision. »

Exemples typiques où la question se pose : envoi d'email (`symfony/mailer` est déjà là — utilise-le), PDF (à vérifier), templating mail, traitement d'image, etc.

## Quand tu coinces

- Si tu ne sais pas où mettre quelque chose : pose la question à le developpeur, qui appellera la responsable technique.
- Si une bizarrerie dans les données te frappe (clés Sage étranges, doublons, etc.) : signale-le à le developpeur, ne « bidouille » pas.
- Si tu veux modifier un fichier hors périmètre : applique la règle « STOP, demande la responsable technique » ci-dessus.
