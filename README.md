# Plateforme de démonstration — mémoire d'expertise comptable, session 2026

**Adresse : https://xasitek.github.io/demo-dec-26/**

Plateforme accompagnant un mémoire d'expertise comptable sur l'optimisation du cycle
créances clients d'un groupe de distribution automobile multimarque et multi-concessions.

L'accès demande un identifiant et un mot de passe, communiqués dans le mémoire déposé.

---

## Toutes les données sont synthétiques

Aucune donnée réelle, aucun compte réel, aucune personne réelle, aucun établissement réel.
Le client du cabinet n'est jamais nommé : le mémoire ne cite qu'une fonction.

Les IBAN sont construits sur un code banque non attribué (`99999`), mathématiquement
valides mais rattachés à aucun établissement bancaire. Les fichiers de virement produits
par la démonstration sont des fichiers de démonstration : **aucun ordre bancaire n'est
transmis, et aucun ne peut l'être.** Les adresses de courriel se terminent par
`.invalid`, domaine réservé qui ne peut pas recevoir de message.

## Ce que contient ce dépôt

| Chemin | Rôle |
|---|---|
| `public/` | la plateforme, entièrement statique. C'est elle que sert l'adresse ci-dessus : elle lit les données synthétiques dans le navigateur, sans serveur. |
| `public/donnees/` | le monde synthétique et sa vérité de référence, en JSON colonnaire. Reconstructible à l'identique par la fabrique. |
| `fabrique/` | le générateur déterministe du monde. Une graine, un monde : deux exécutions donnent des fichiers identiques au bit près. |
| `app/` | l'application Symfony 7.4 — le module de remboursement client instrumenté, ses quatre postes de travail et ses contrôles. Elle demande un serveur PHP et PostgreSQL. |
| `outils/serveur.js` | un serveur statique minimal, pour ouvrir la plateforme en local. |

## Ouvrir la plateforme en local

```bash
npm run demo          # puis http://localhost:8080
```

Sous Windows, `LANCER-DEMO.bat` fait la même chose et ouvre le navigateur.

## Reconstruire le monde synthétique

```bash
npm run fabrique
```

La fabrique est déterministe et **additive** : chaque outil tire sur sa propre graine et
n'écrit que ses propres tables, si bien que les mondes déjà figés restent identiques au
bit près. C'est ce qui permet de publier une mesure et de la retrouver ensuite.

## L'application Symfony

`app/` est un module de production instrumenté pour la démonstration, pas une maquette :
vrai workflow, vraies transitions gardées par rôle, vraie génération des fichiers
comptables et de virement, vrais contrôles. Elle distingue toujours deux ensembles, et
ne les additionne jamais :

- **54 contrôles hérités** du circuit de production — 34 bloquants, 14 alertes,
  6 informatifs ;
- **4 renforcements ajoutés pour la démonstration** — surpaiement rejoué devant la
  caisse, idempotence forte de la génération, chaîne d'intégrité du journal, et
  vérification de la clé de contrôle IBAN (MOD 97).

Le registre se recompte sur le code : `php bin/console app:demo:registre-controles`
échoue si un seul fragment de code attendu a disparu.

### Déploiement

L'application a besoin de PHP 8.3 et de PostgreSQL 16. Le dépôt ne contient **aucun
identifiant** : la porte d'accès se configure en variables d'environnement.

```bash
DEMO_ACCES_IDENTIFIANT=jury
DEMO_ACCES_EMPREINTE_B64=$(php -r 'echo base64_encode(password_hash("VOTRE-MOT-DE-PASSE", PASSWORD_BCRYPT));')
DEMO_VISITES_CLE=<une chaîne aléatoire, pour l'écran de fréquentation>
```

Vide, `DEMO_ACCES_EMPREINTE_B64` fait refuser tout le monde — c'est le bon comportement
par défaut si quelqu'un déploie cette copie sans y penser.

## Ce que la porte d'accès protège, et ce qu'elle ne protège pas

Il y a **deux portes**, et elles n'ont pas la même nature. La dire franchement vaut mieux
que la laisser croire.

- **Sur la plateforme statique** (l'adresse ci-dessus) : un site sans serveur ne peut rien
  vérifier ailleurs que dans le navigateur du visiteur. C'est un **filtre d'entrée**, pas
  une sécurité : qui sait lire du JavaScript passe outre. Cela ne coûte rien, puisqu'il
  n'y a rien à protéger — toutes les données sont synthétiques.
- **Sur l'application Symfony** : la porte est réelle. Le mot de passe n'est jamais stocké
  en clair, la vérification a lieu sur le serveur, la session expire, et la porte se ferme
  quelques minutes après plusieurs tentatives.

## Fréquentation

- **la plateforme statique** : `gh api repos/xasitek/demo-dec-26/traffic/views` donne les
  vues et les visiteurs uniques sur quatorze jours, lisibles par le propriétaire du dépôt
  seul, sans traqueur ni service tiers ;
- **l'application** : un compteur interne date chaque visite ayant franchi la porte,
  compte les pages consultées et note les postes de travail pris
  (`php bin/console app:demo:visites`, ou l'écran `/demo/visites/<clé>`). Il ne stocke
  **aucune adresse IP, aucun nom, aucun identifiant de session**.
