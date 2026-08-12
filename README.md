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
git clone https://github.com/Progress-cmd/pi5_unison.git \
cd pi5_unison/docker \
nano .env
```Bash
# --- ENVIRONNEMENT ---
DOCKER_TARGET=production

# --- BASE DE DONNÉES ---
DB_ROOTPASS=password
DB_NAME=unison
DB_USER=user
DB_PASS=password

# --- MEILISEARCH ---
MS_PASS=password

# --- MAIL ---
MAIL_HOST=hôte
MAIL_PORT=port
MAIL_USER=user
MAIL_PASS=APIkey

# --- RESEAU ---
PORT_EXPOSE=8082`
```
docker compose -f docker-compose-prod.yml up -d

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
├── src/                    # Fichiers sources \
├── .*ignore                # Fichier d'exclusion de partie \
├── composer.*              # Fichier d'initialisation des dépendances via `composeur` \
├── notes.md                # Avancée du projet
└── README.md               # Ce fichier ici présent

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