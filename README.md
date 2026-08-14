# Unison
Une application web d'écoute et de partage interne de musique.

## Table des matières
- [Prérequis](#Prérequis)
- [Installation](#Installation)
- [Utilisation](#Utilisation)
- [Configuration (.env)](#Configuration)
- [Contribution](#Contribuer)
- [Tests](#Tests)
- [Licence](#Licence)

## Prérequis
- Docker >= 4.69.0
- Linux, distribution `Debian` (ou dérivé)
- Connexion internet fiable

## Installation
```bash
git clone https://github.com/Progress-cmd/pi5_unison.git
cd pi5_unison

# Le .env n'est pas versionné : partir du modèle, qui liste TOUTES
# les variables attendues et explique à quoi chacune sert.
cp docker/.env.exemple docker/.env
nano docker/.env

docker compose -f docker/docker-compose-prod.yml --env-file docker/.env up -d
```

> Une variable oubliée se signale au démarrage par
> `WARN The "X" variable is not set. Defaulting to a blank string.`
> Quand une variable est ajoutée au code, elle doit l'être dans
> `docker/.env.exemple` — c'est la seule liste de référence.

## Utilisation
### Accès à l'application en local
- http://localhost:8082
### En mode "developpement"
- Dashboard MeiliSearch : http://localhost:7700/
- Dashboard MailHog : http://localhost:8025
### Accès internes
- app : `docker compose exec -it app bash`
- db : `docker compose exec db mariadb -u root -p`

## Configuration
| Variable      | Défaut          | Description                           |
|---------------|-----------------|---------------------------------------|
| DOCKER_TARGET | production      | Définition du mode de mise en place   |
| PORT_EXPOSE   | 8082            | Port d'exposition de l'application    |
| APP_URL       | —               | URL publique, pour les liens envoyés par mail |
| DB_ROOTPASS   | —               | Mot de passe root de la database      |
| DB_NAME       | —               | Nom de la database                    |
| DB_USER       | —               | Nom d'utilisateur de la database      |
| DB_PASS       | —               | Mot de passe de cet utilisateur       |
| MS_PASS       | —               | Clé API vers Meilisearch (à définir)  |
| MAIL_HOST     | smtp.resend.com | Nom d'hôte du service de mail utilisé |
| MAIL_PORT     | 587             | Port utilisé par celui-ci             |
| MAIL_USER     | resend          | Nom d'utilisateur donné               |
| MAIL_PASS     | ?               | Clé API donnée                        |

├── docker/                 # Création du conteneur \
├── meilisearch_init/       # Initialisation de l'outil de recherche avancé \
├── mysqlinit/              # Initialisation de la base de donnée \
├── demo_data/              # Environnement de démonstration (base et contenu fictifs) \
├── maj/                    # Boîte aux lettres des mises à jour (contenu non versionné) \
├── src/                    # Fichiers sources \
├── .*ignore                # Fichier d'exclusion de partie \
├── composer.*              # Fichier d'initialisation des dépendances via `composeur` \
├── notes.md                # Avancée du projet
└── README.md               # Ce fichier ici présent

## Compte d'administration

Un troisième compte, distinct des comptes d'écoute, donne accès à une section
de gestion : contenu, stockage, comptes, maintenance, mise à jour des
conteneurs, journal d'activité et console.

### Installation

```bash
# 1. Migrations de schéma (base existante uniquement)
# Ne PAS sourcer le .env : un mot de passe contenant une espace ou un $
# serait interprété par le shell. On lit les valeurs telles quelles.
DB_ROOTPASS=$(sed -n 's/^DB_ROOTPASS=//p' docker/.env | head -1)
DB_NAME=$(sed -n 's/^DB_NAME=//p' docker/.env | head -1)

for m in mysql_init/migrations/*.sql; do
    echo "→ $m"
    docker compose -f docker/docker-compose-dev.yml exec -T db \
        mariadb -u root -p"$DB_ROOTPASS" "$DB_NAME" < "$m"
done

# 2. Créer le compte (mot de passe saisi de façon interactive, jamais versionné)
docker compose -f docker/docker-compose-dev.yml exec app \
    php /var/www/html/src/includes/creerAdmin.php
```

Les migrations sont idempotentes : les rejouer ne casse rien. Sur une base
vierge, rien de tout cela n'est nécessaire — `mysql_init/dump.sql` contient
déjà tout.

Choisir un **identifiant non devinable** : la page de connexion ne propose pas
ce compte et exige sa saisie manuelle, son nom fait donc partie du secret.

### Connexion

Lien discret « Accès technique » en bas de la page de connexion. Il ouvre un
formulaire demandant identifiant **et** mot de passe. Ce chemin est réservé aux
comptes `admin` : un compte normal y est refusé même avec le bon mot de passe,
sans quoi il annulerait l'anonymisation de la page publique.

Limite dédiée : 3 tentatives par demi-heure, en plus du compteur général.

### Journal d'activité

La page **Journal** rassemble ce qui se passe sur l'installation : connexions
réussies et échouées, blocages du limiteur de tentatives, opérations
d'administration, importations, et incidents PHP (exceptions non rattrapées,
erreurs fatales). En production les erreurs sont masquées à l'écran ; sans ce
journal, elles n'étaient visibles que dans `docker compose logs app`.

Filtres par niveau, canal, période et recherche libre ; rafraîchissement
automatique pour suivre une opération en cours. Chaque événement peut porter un
contexte JSON replié — pour une importation ratée, les dernières lignes de
`yt-dlp`, c'est-à-dire exactement ce qui manquait pour diagnostiquer.

Les événements de plus de **90 jours** sont supprimés automatiquement, au plus
une fois par jour, à la consultation de la section. Purge manuelle possible.

> Le journal est écrit dans la base **principale**, y compris depuis une session
> de démonstration : sinon ses traces atterriraient dans la base de
> démonstration, que l'administration n'ouvre jamais.

### Console

La page **Console** est un terminal pour interroger le projet sans ouvrir de
shell dans le conteneur. **Toutes les commandes sont en lecture seule** : rien
n'y modifie, ne supprime ni ne crée quoi que ce soit. Les opérations
destructrices gardent leurs pages dédiées et leurs confirmations.

`aide` donne la liste ; ↑ ↓ rappellent l'historique, Tab complète.

| Commande | Effet |
|---|---|
| `sante` | Base, recherche, disque, PHP, anomalies, incidents des 24 h |
| `titre <id\|texte>` | Fiche complète : artistes, genres, playlists, écoutes, fichier |
| `artiste`, `playlist`, `compte` | Fiches et rattachements |
| `tables`, `decrire <table>` | Structure de la base, clés étrangères comprises |
| `doublons`, `orphelins`, `manquants` | Incohérences entre le disque et la base |
| `top`, `ecoutes`, `recents` | Statistiques d'usage |
| `journal [niveau\|canal] [n]` | Derniers événements, sans quitter le terminal |
| `base principale\|demo` | Change la base interrogée |
| `sql <SELECT …>` | Requête libre, strictement en lecture |

La commande `sql` est encadrée par trois mécanismes **indépendants** : liste
blanche de verbes de lecture et instruction unique ; exécution dans une
transaction `READ ONLY`, où MariaDB refuse elle-même toute écriture quel que
soit ce qui aurait passé le filtre ; et un temps d'exécution maximal. Le
filtrage syntaxique est le plus fragile des trois — il n'est pas le seul.

### Terminal SQL

Page distincte de la console : un **vrai client SQL**. Tout ce qui n'est pas
préfixé d'une contre-oblique part directement à MariaDB. Les méta-commandes
suivent la convention de `psql` :

| Méta-commande | Effet |
|---|---|
| `\aide` | Liste des méta-commandes et mode courant |
| `\tables` (`\dt`) | Tables de la base courante, volume et poids |
| `\d <table>` | Colonnes, clés étrangères et index |
| `\base [principale\|demo]` | Change la base interrogée |
| `\ecriture [on\|off]` | Autorise les modifications pour 15 minutes |
| `\effacer` | Vide l'écran |

Entrée exécute, **Maj+Entrée passe à la ligne** — une requête un peu longue se
lit mieux sur plusieurs lignes. ↑ ↓ rappellent l'historique, Tab complète les
méta-commandes et les noms de tables.

Trois garde-fous, par ordre d'importance :

1. **Lecture seule par défaut.** À l'ouverture, les requêtes s'exécutent dans
   une transaction `READ ONLY` : MariaDB refuse elle-même toute écriture. Il
   faut taper `\ecriture on`, et cette autorisation **expire d'elle-même au bout
   d'un quart d'heure**. Le terminal change de couleur tant qu'elle est active :
   on ne modifie jamais la base sans le savoir.
2. **Safe updates.** Un `UPDATE` ou un `DELETE` sans `WHERE` est refusé, comme
   avec l'option `--safe-updates` du client `mysql`. Ajouter `WHERE 1=1` lève le
   refus, si c'est bien l'intention.
3. **Interdits définitifs.** Comptes, privilèges, bases entières et accès aux
   fichiers (`GRANT`, `DROP DATABASE`, `INTO OUTFILE`, `LOAD DATA`…) ne sont
   jamais acceptés, quel que soit le mode. La détection se fait sur une version
   normalisée : ni un commentaire ni des espaces multiples ne la contournent.

Chaque instruction est journalisée avec son texte exact — lecture en `info`,
écriture en `attention`, modification de structure en `critique`.

> Ce terminal ne connaît rien au projet : il ne réindexe pas MeiliSearch et ne
> supprime pas les fichiers audio d'un titre effacé. Pour tout ce que les pages
> Contenu et Stockage savent faire, elles restent le bon outil.
> Avant d'écrire : `backup_unison`.

### Mise à jour des conteneurs

Unison n'a **aucun accès à Docker**. La page de maintenance dépose un fichier
dans un dossier partagé, qu'un script de l'hôte ramasse. Le conteneur web ne
peut rien faire d'autre que créer ce signal.

```bash
# Sur le Pi, une seule fois
mkdir -p /home/Francis/apps/pi5_unison/maj
sudo chown 33:33 /home/Francis/apps/pi5_unison/maj   # uid de www-data
cp docker/update_unison.exemple.sh /home/Francis/apps/pi5_unison/update_unison.sh
chmod +x /home/Francis/apps/pi5_unison/update_unison.sh
chmod +x /home/Francis/apps/pi5_unison/docker/appliquer_migrations.sh
cp docker/bash_aliases.exemple.sh ~/.bash_aliases && . ~/.bash_aliases
```

#### Les deux pièges d'un déploiement

Un `git pull` suivi d'un `apache2ctl graceful` ne suffit pas toujours, et les
deux cas où il ne suffit pas sont **silencieux** : le déploiement paraît réussi,
la fonctionnalité manque.

**1. Ce qui est cuit dans l'image n'est pas rechargé.** `src/` est monté en
volume, donc le code PHP s'applique tout de suite. Mais `vendor/` (donc
`composer.json` et `composer.lock`), `docker/*.ini` — dont `security.ini`, qui
porte `open_basedir` et `disable_functions` —, `000-default.conf`, le
`Dockerfile` et `meilisearch_init/` sont **copiés à la construction**. Les
mettre à jour exige `up -d --build` : un `up -d` seul réutilise l'image
existante.

**2. Le schéma de la base ne bouge pas.** `mysql_init/` n'est rejoué par Docker
que sur une base **vierge**. Sur une installation en service, la table qu'attend
une nouvelle fonctionnalité n'est jamais créée sans passer par les migrations.

`update_unison.sh` traite les deux : il compare les commits avant/après le pull,
reconstruit **seulement** si un fichier embarqué dans l'image a changé, et
applique systématiquement les migrations en attente. Passer
`RECONSTRUCTION_AUTO=0` en tête du script si vous préférez être prévenu plutôt
qu'interrompu — il signalera alors qu'une reconstruction est nécessaire.

#### La commande `unison`

Tout le pilotage du serveur passe par une seule commande. Sans argument, elle
ouvre un menu :

```bash
unison            # menu interactif
unison status     # ou directement une sous-commande
unison aide       # liste complète
```

Installation par **lien symbolique**, pas par copie — le dépôt reste la seule
source, et un `git pull` met la commande à jour tout seul :

```bash
sudo ln -sfn /home/Francis/apps/pi5_unison/docker/unison.sh /usr/local/bin/unison
```

| Sous-commande | Effet |
|---|---|
| `status` | version, conteneurs, schéma, cron, disque |
| `update` | pull + migrations + rechargement — sans coupure |
| `upgrade` | pull + reconstruction `--build` — coupure de service |
| `reload` | pull + rechargement du code PHP seulement |
| `migrations` | migrations en attente, puis application |
| `backup` | sauvegarde compressée et datée (les 10 dernières sont conservées) |
| `journal [n]` | derniers événements du journal applicatif |
| `logs [n]` | logs Docker de l'application, en direct |
| `sql`, `shell` | client SQL, shell dans le conteneur |
| `start`, `stop`, `restart` | conteneurs |
| `cron [on\|off\|toggle]` | mise à jour automatique |

**Le cron** est la ligne de crontab qui déclenche `update_unison.sh` chaque
minute. `unison cron off` la désactive le temps d'une intervention — sans ça,
le cron peut relancer un pull ou une reconstruction au milieu d'une
manipulation. La ligne est **commentée et non supprimée**, donc `unison cron on`
la retrouve à l'identique. Le reste de la crontab n'est jamais touché, et une
sauvegarde est déposée dans `/tmp` avant chaque modification.

> À la restauration, utilisez `crontab - < fichier` et non `crontab fichier` :
> la commande `crontab` tronque les noms de fichiers longs et remplacerait la
> crontab par le contenu d'un chemin inexistant.

#### Alias disponibles

Les alias ne sont que des raccourcis vers `unison` — une seule implémentation,
qui ne peut donc pas diverger.

| Alias | Ce qu'il fait | Coupure |
|---|---|---|
| `reload_unison` | pull + rechargement Apache | aucune |
| `update_unison` | pull + **migrations** + rechargement | aucune |
| `upgrade_unison` | pull + **`--build`** + migrations + redémarrage | quelques minutes |
| `migrations_unison` | migrations en attente | — |
| `status_unison` | commit déployé, version, conteneurs, schéma, cron | — |
| `backup_unison` | sauvegarde datée et compressée de la base | — |
| `cron_unison [on\|off]` | mise à jour automatique | — |
| `journal_unison`, `logs_unison`, `shell_unison`, `sql_unison` | diagnostic | — |

En cas de doute : `upgrade_unison`. Il est plus lent, jamais faux.

#### Migrations

```bash
./docker/appliquer_migrations.sh --liste   # ce qui est en attente
./docker/appliquer_migrations.sh           # application (production)
./docker/appliquer_migrations.sh --dev     # sur l'environnement de dev
```

Les migrations appliquées sont enregistrées dans `schema_migrations` : chacune
n'est jouée qu'une fois, et le script s'arrête à la première en échec plutôt que
de continuer sur un schéma à moitié migré. Elles restent malgré tout écrites de
façon idempotente, pour qu'une réexécution soit sans conséquence.

Le cron existant (`* * * * *`) reste inchangé. Le script d'exemple conserve le
comportement actuel — pull automatique et rechargement gracieux — et y ajoute
le traitement des demandes.

> La demande ne transporte **aucun paramètre** : seulement une action choisie
> dans une liste blanche. Ne jamais y ajouter de champ `branche`, `tag` ou
> `commande` : ce serait une exécution de commande à distance.

## Mode démonstration

Un bouton « Découvrir la démo » sur la page de connexion ouvre une session de
présentation, pensée pour un portfolio.

```bash
./demo_data/installer.sh          # conteneur de développement
COMPOSE=docker/docker-compose-prod.yml ./demo_data/installer.sh
```

Le script est rejouable : le relancer remet la démonstration à son état
d'origine, ce qui est la façon prévue de la nettoyer après une présentation.

Cette session est **isolée de l'application**, à trois niveaux :

| Ressource | Application | Démonstration |
|---|---|---|
| Base de données | `$DB_NAME` | `$DB_NAME_demo` |
| Fichiers audio | `music_data/` | `music_data/demo/` |
| Index de recherche | `musiques`, `artists` | `musiques_demo`, `artists_demo` |

L'aiguillage se fait dans `Config` (`src/includes/config.php`) et vaut pour
toutes les requêtes : aucune donnée personnelle n'est atteignable depuis la
démonstration, même en cas d'oubli de filtre dans une requête.

La session est en outre en **lecture seule** — `refuserSiDemo()` refuse toute
écriture côté serveur — et le catalogue de démonstration est entièrement
fictif, avec des fichiers audio générés à la synthèse. Rien de ce qui y est
diffusé n'appartient à un tiers, ce qui rend la démonstration publiable sans
question de droits d'auteur.

## Contribuer
Pas de contribution demandée, ce n'est qu'un projet personnel

## Tests
Les tests c'est quand ça marche pas, donc pas besoin ;)

## Licence
Ceci est un projet personnel, il n'a pas vocation à être utilisé par autrui. La *consultation*, le *téléchargement* et l'*utilisation* sont **autorisés**.
Se référer aux licences du github de [yt-dlp](ttps://github.com/yt-dlp/yt-dlp/blob/master/LICENSE) et à celles utilisés pour la création de conteneur