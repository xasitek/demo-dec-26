# Mettre les vrais outils en ligne

La plateforme statique tourne déjà sur `https://xasitek.github.io/demo-dec-26/` et ne
demande aucun serveur. **Ce document concerne l'application Symfony** : les vrais écrans,
le vrai workflow, la vraie porte d'accès vérifiée côté serveur.

---

## Ce qu'il faut, et ce que ça coûte

L'application a besoin de deux choses : un serveur qui exécute PHP 8.3, et une base
PostgreSQL 16. La base pèse **143 Mo** une fois le monde synthétique chargé — mesuré, pas
estimé.

| Hébergeur | Configuration | Prix mensuel | Ce qu'il faut savoir |
|---|---|---|---|
| **Render** (recommandé) | Web *Starter* + Postgres *Basic 256 Mo* | ≈ 13 $ | L'application est déjà taillée pour lui : `Dockerfile` FrankenPHP, gestion du `$PORT`, worker embarqué. Le plan `render.yaml` de ce dépôt crée tout d'un coup. |
| Render, plans gratuits | Web *Free* + Postgres *Free* | 0 $ | **Deux pièges.** Le service s'endort après quinze minutes sans visite : le jury attend alors près d'une minute au premier clic. Et la base gratuite **expire au bout de trente jours**. Utilisable pour une répétition, pas pour une adresse qu'on communique. |
| VPS (Hetzner, Scaleway…) | 2 vCPU, 4 Go | 4 à 6 € | Aucun endormissement, contrôle total, mais c'est vous qui installez Docker, le reverse proxy et le certificat. |

**Le conseil, franchement.** Une adresse communiquée à un jury doit répondre en une
seconde, un mardi soir de janvier, sans qu'on y pense. Treize dollars par mois pendant les
mois qui séparent le dépôt de la soutenance, c'est le prix de ne pas avoir à y penser.
Prenez le plan gratuit seulement pour répéter, et basculez avant de diffuser l'adresse.

---

## Le déploiement, étape par étape

### 1. Créer les services

Sur Render : **New > Blueprint**, puis pointer ce dépôt. Render lit `render.yaml` et crée
le serveur web et la base. Il réclamera **une seule valeur** : `DEMO_ACCES_EMPREINTE_B64`,
l'empreinte du mot de passe. Elle se fabrique sur votre poste :

```bash
php -r 'echo base64_encode(password_hash("DEC2026", PASSWORD_BCRYPT));'
```

Le mot de passe lui-même n'est écrit nulle part, ni dans ce dépôt ni chez l'hébergeur :
seule son empreinte bcrypt voyage. Laissée vide, la porte refuse tout le monde — c'est le
bon comportement par défaut.

### 2. Jouer les migrations

Une fois la base créée, récupérer sa **connexion externe** dans Render, puis depuis votre
poste :

```bash
export DATABASE_URL="<connexion externe de Render>"
php bin/console doctrine:migrations:migrate --no-interaction
```

### 3. Installer la plateforme

Une seule commande, toujours depuis votre poste, avec le même `DATABASE_URL` :

```bash
php bin/console app:demo:installer
```

Elle enchaîne les dix étapes dans le bon ordre — les postes de travail, les quatre mondes
d'outils, les compteurs — puis joue cinq vérifications : les huit réconciliations du
cockpit, le contrôle de conformité des grands comptes, le registre des 54 contrôles et
4 renforcements, le gel du contrat de mesure, et l'absence de dépendance extérieure. Une
plateforme installée mais fausse n'est pas installée : la commande le dit et sort en
erreur.

Elle est **idempotente**. Chaque étape porte un témoin ; relancée, elle passe ce qui est
déjà fait. Après un déploiement interrompu, on relance sans réfléchir. `--refaire` force
tout à être rechargé.

Pourquoi depuis votre poste et non depuis le conteneur : le monde synthétique pèse
quarante mégaoctets et n'a aucune raison de vivre dans l'image. Le chargement se fait une
fois, par la connexion externe de la base ; l'image reste légère et se reconstruit vite.

### 4. Ouvrir la porte sur la page d'accès

Dans `public/index.html`, remplacer :

```html
<span class="attente">Ouverture de l'accès en cours de déploiement</span>
```

par le lien vers l'application :

```html
<a class="entree" href="https://VOTRE-SERVICE.onrender.com/acces">Entrer dans la plateforme</a>
```

Puis `git commit` et `git push` : GitHub Pages republie en une minute. **L'adresse
`github.io` ne change jamais** — c'est tout l'intérêt des deux étages. Si l'application
déménage un jour, cette ligne seule bouge, et l'adresse déjà communiquée reste valable.

---

## Vérifier que tout tient

```bash
php bin/console app:demo:registre-controles      # 54 contrôles hérités + 4 renforcements
php bin/console app:demo:garde-iban-mod97 --mot-de-passe=…   # les deux étages de R04
php bin/console app:demo:mesure-blind-o8 --par-classe=2      # la mesure, en répétition
php bin/console app:demo:files-par-profil --mot-de-passe=…   # les quatre postes, écrans réels
php bin/console app:demo:visites --epreuve --mot-de-passe=…  # l'écran de fréquentation
```

Et le parcours complet d'un dossier, du dépôt au fichier de paiement :

```bash
php bin/console app:remboursement:preparer-vedette
php bin/console app:remboursement:parcours-vedette --reference=<REF> --mot-de-passe=…
php bin/console app:demo:reset-remboursement --reference=<REF>   # remise à l'état jouable
```

---

## Remettre la démonstration à zéro

Le dossier vedette se rejoue autant de fois qu'on veut :

```bash
php bin/console app:demo:reset-remboursement --reference=<REF>
```

La commande refuse de tourner ailleurs qu'en environnement `demo`, elle n'existe qu'en
console, et elle n'est atteignable depuis aucun écran. Elle ne touche qu'au monde de
session : la saisie d'origine, elle, appartient au monde synthétique et n'est jamais
réécrite.

---

## Ce que l'hébergeur ne peut pas faire partir

Par construction, et c'est vérifiable :

- **aucun courriel** : les trois transports sont des puits (`null://null`) ;
- **aucun appel extérieur** : le client HTTP de la démonstration refuse toute sortie
  réseau. La construction de l'image elle-même ne télécharge rien — les deux paquets
  front sont versionnés dans `app/assets/vendor/` exprès ;
- **aucun ordre bancaire** : les fichiers de virement produits sont des fichiers de
  démonstration, sur des IBAN dont le code banque n'est attribué à personne ;
- **aucune donnée réelle** : le monde entier sort d'une fabrique déterministe.

`php bin/console app:demo:verifier-isolation` le rejoue et l'affiche.
