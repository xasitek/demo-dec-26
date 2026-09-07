# Neutralisation de la copie de démonstration

> Cette copie est **indépendante**. Le dépôt d'origine est en **lecture seule absolue** :
> il n'a reçu aucun commit, aucune branche, aucune modification. La copie n'a **aucun
> lien Git** avec lui, et aucun moyen technique d'y pousser quoi que ce soit.

---

## 1. Le lien Git est supprimé, pas seulement débranché

Le dossier `.git` de la copie a été **supprimé**. Il n'y a donc ni dépôt distant, ni
historique, ni référence vers le compte d'origine. Retirer un dépôt distant se défait
d'une commande ; supprimer le dépôt local rend la poussée **impossible**, ce qui est
une garantie d'une autre nature.

La copie est en outre **exclue du dépôt du mémoire** (`.gitignore` du projet), pour
qu'elle ne puisse pas y être versionnée par inadvertance.

---

## 2. Toutes les sorties sont neutralisées

| Sortie | En production | Dans la copie |
|---|---|---|
| Base de données | PostgreSQL du groupe | **conteneur dédié, port 55432**. Le PostgreSQL éventuellement présent sur 5432 n'est jamais touché |
| Miroir du progiciel comptable | copie quotidienne du progiciel | **aucune**. Les tables miroir sont peuplées par la fabrique synthétique |
| Messagerie sortante | fournisseur d'envoi | **`null://null`** : rien ne part, jamais |
| Courrier entrant | boîtes IMAP et POP3 | **désactivées**, aucune adresse configurée |
| Temps réel | concentrateur Mercure | **point local inerte** : accepte les publications, ne diffuse à personne |
| Identité | fournisseur d'identité de l'entreprise | **choix de poste** parmi quatre profils synthétiques |
| Classeurs externes | trois classeurs de production | **aucun** |
| Orchestration de flux | moteur externe | **aucun** |
| Lecture des pièces | modèle de langue distant, clé d'API | **fournisseur simulé**, résultats préparés, aucune clé |
| Jetons applicatifs | jeton d'interface de programmation | **vide** |
| Fusion de documents | binaire externe | **optionnelle**, repli gracieux si absente |
| Fichier de virement | produit puis transmis à la banque | **produit et validé localement, jamais transmis** |

**Aucun de ces éléments n'est nécessaire au fonctionnement de la démonstration.** Si tous
les systèmes du groupe disparaissaient, la copie continuerait de fonctionner seule.

---

## 3. Les secrets de production ont été détruits

Le dépôt d'origine porte ses fichiers d'environnement dans le versionnement. La copie les
a donc reçus, avec **vingt-six valeurs réelles** : clé d'interface de programmation d'un
modèle de langue, secret client d'identité, compte de service, mots de passe de boîtes
aux lettres, mot de passe de base, secret de concentrateur, jeton applicatif, chaîne de
connexion au progiciel.

**Ils ont été écrasés dès la copie faite.** Le fichier d'environnement de la copie ne
contient que des valeurs de démonstration, dont aucune n'ouvre quoi que ce soit. Un
balayage a confirmé qu'aucun autre motif de secret ne subsiste dans la copie.

> **Point à traiter côté groupe, et il ne concerne pas cette copie.** Ces valeurs restent
> présentes dans l'historique du dépôt d'origine. C'est une observation d'audit, pas une
> action à mener ici : le dépôt d'origine ne se touche pas.

---

## 4. L'identité du client est neutralisée

Reprendre l'ergonomie d'une application ne veut pas dire reprendre l'identité de son
client. Dans la copie seulement :

- **nom du groupe** : remplacé partout, y compris dans les noms de classes de style, qui
  apparaissent dans le code source des pages ;
- **domaine de messagerie réel** : remplacé par `demonstration.invalid`, domaine réservé
  par la norme et impossible à enregistrer ;
- **nom de l'application** : « Finance Créances — démonstration DEC » ;
- **logos** : remplacés par des images neutres ;
- **noms de progiciels** visibles à l'écran : remplacés par leur fonction ;
- **domaines de tiers** cités dans les commentaires : remplacés ;
- **établissements, sociétés, personnes, contreparties** : tous synthétiques.

Deux vérifications sont passées, et elles donnent zéro : aucune occurrence du nom réel
dans le code de la copie, aucune dans la base de démonstration, colonne par colonne.

---

## 4 bis. Une porte d'accès, avant tout le reste

La plateforme n'est pas ouverte au premier qui reçoit le lien. Un **identifiant et un mot de
passe** sont exigés avant d'atteindre le portail des dix outils.

**Ce n'est pas une protection de données** : il n'y en a aucune à protéger. C'est une porte,
et elle sert à trois choses. Éviter qu'un visiteur arrive ici par hasard. Donner un cadre
professionnel. Ne pas exposer la plateforme depuis le seul lien.

**Elle est néanmoins réelle**, et pas un simple champ de formulaire :

- le mot de passe n'est **jamais stocké** : seule son empreinte l'est, en `bcrypt` de coût 12 ;
- l'identifiant est comparé en **temps constant** ;
- la session vit **côté serveur**, l'identifiant de session est **régénéré** à la connexion ;
- elle **expire au bout de huit heures**, en glissant à chaque page consultée ;
- au-delà de **huit tentatives**, l'accès se ferme cinq minutes, avec une temporisation croissante entre les essais ;
- un bouton **Quitter** détruit la session.

Un seul compte, transmis séparément du lien. Aucune création de compte, aucun mot de passe
oublié, aucun courriel, aucun second facteur.

**La porte est indépendante du choix de poste.** La porte dit « vous pouvez entrer » ; le poste
dit « voici avec quels yeux vous regardez ». Les deux ne se confondent jamais.

---

## 5. Le mode démonstration ne peut pas se confondre avec la production

`APP_ENV=demo`, environnement distinct, et un **bandeau permanent** en tête de chaque
écran :

> **ENVIRONNEMENT DE DÉMONSTRATION** — données entièrement synthétiques, aucune donnée
> client, aucune connexion externe

La connexion se fait par choix de poste, sans mot de passe : un environnement de
production ne se comporte jamais ainsi.

---

## 6. Démarrer

```
docker run -d --name finance-demo-db -e POSTGRES_DB=finance_demo \
  -e POSTGRES_USER=demo -e POSTGRES_PASSWORD=demo \
  -p 127.0.0.1:55432:5432 postgres:16-alpine

composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:demo:preparer
php bin/console tailwind:build
php bin/console asset-map:compile
php -S 127.0.0.1:8123 -t public demo-routeur.php
```

Puis `http://127.0.0.1:8123/` — l'écran d'accès s'ouvre.

Ou, sous Windows, `LANCER-DEMO.bat`.
