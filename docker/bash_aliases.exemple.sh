# Alias de gestion d'Unison — à recopier dans ~/.bash_aliases sur le Pi.
#
#   cp docker/bash_aliases.exemple.sh ~/.bash_aliases
#   . ~/.bash_aliases
#
# LE PIÈGE QUE CES FONCTIONS ÉVITENT
# ----------------------------------
# « docker compose up -d » réutilise l'image existante. Or src/ est monté en
# volume, mais PAS le reste : vendor/ (composer.json, composer.lock), les
# fichiers docker/*.ini — security.ini, open_basedir, disable_functions —,
# 000-default.conf, le Dockerfile et meilisearch_init/init.sh sont copiés à la
# construction de l'image. Sans « --build », ils ne sont jamais mis à jour :
# le déploiement paraît réussi, et le correctif attendu n'est pas là.
#
# De la même façon, mysql_init/ n'est rejoué par Docker que sur une base
# VIERGE : sur une installation en service, une nouvelle table n'est jamais
# créée sans passer par les migrations.
#
# D'où trois fonctions, du plus léger au plus lourd :
#
#   reload_unison   code PHP seulement, sans coupure          — quelques secondes
#   update_unison   + dépendances et migrations, sans build   — quelques secondes
#   upgrade_unison  + reconstruction complète de l'image      — plusieurs minutes
#
# En cas de doute : upgrade_unison. Il est plus lent, jamais faux.

UNISON_DEPOT=/home/Francis/apps/pi5_unison
UNISON_COMPOSE="docker/docker-compose-prod.yml"
UNISON_ENV="docker/.env"

# Raccourci interne : docker compose avec les bons fichiers.
_unison_dc() {
    docker compose -f "$UNISON_COMPOSE" --env-file "$UNISON_ENV" "$@"
}

# Se placer dans le dépôt, ou échouer proprement.
_unison_cd() {
    cd "$UNISON_DEPOT" || {
        echo "✗ Dépôt introuvable : $UNISON_DEPOT" >&2
        return 1
    }
}

# --- Rechargement : le code PHP a changé, rien d'autre -----------------------
# src/ étant monté en volume, le code est déjà à jour dès le pull ; le
# rechargement d'Apache ne sert qu'à vider les caches d'opcode.
reload_unison() {
    _unison_cd || return 1

    git pull --ff-only || return 1
    _unison_dc exec -T app apache2ctl graceful && echo "✓ Application rechargée"
}

# --- Mise à jour courante ---------------------------------------------------
# Le cas normal : on récupère le code, on applique les migrations de schéma en
# attente, et on recharge. Aucune coupure de service.
update_unison() {
    _unison_cd || return 1

    git pull || return 1

    ./docker/appliquer_migrations.sh || {
        echo "⚠ Migrations en échec — le schéma est peut-être incomplet" >&2
    }

    _unison_dc exec -T app apache2ctl graceful && echo "✓ Unison mis à jour"
}

# --- Mise à jour complète ---------------------------------------------------
# Reconstruit l'image : nécessaire dès que le Dockerfile, un fichier .ini, la
# configuration Apache, composer.lock ou meilisearch_init/ ont changé.
# Interrompt le service le temps de la reconstruction.
upgrade_unison() {
    _unison_cd || return 1

    git pull || return 1

    _unison_dc down
    docker container prune -f

    # « --build » est le cœur de cette fonction : c'est ce qui la distingue de
    # update_unison, et l'oublier revient à ne rien avoir reconstruit du tout.
    _unison_dc up -d --build || {
        echo "✗ Reconstruction en échec" >&2
        return 1
    }

    ./docker/appliquer_migrations.sh || {
        echo "⚠ Migrations en échec — le schéma est peut-être incomplet" >&2
    }

    echo "✓ Unison reconstruit et redémarré"
}

# --- Diagnostic -------------------------------------------------------------

# Migrations en attente, sans rien appliquer.
migrations_unison() {
    _unison_cd || return 1
    ./docker/appliquer_migrations.sh --liste
}

# État des conteneurs et version déployée.
status_unison() {
    _unison_cd || return 1

    echo "— Commit déployé —"
    git log -1 --format='%h %ad %s' --date=short

    echo
    echo "— Version applicative —"
    sed -n "s/^const UNISON_VERSION = '\(.*\)';/\1/p" src/includes/version.php

    echo
    echo "— Conteneurs —"
    _unison_dc ps

    echo
    echo "— Schéma —"
    ./docker/appliquer_migrations.sh --liste
}

# Journaux applicatifs. « logs_unison 200 » pour remonter plus loin.
logs_unison() {
    _unison_cd || return 1
    _unison_dc logs --tail "${1:-50}" -f app
}

# Shell dans le conteneur applicatif.
shell_unison() {
    _unison_cd || return 1
    _unison_dc exec app bash
}

# Client SQL sur la base principale.
# Le mot de passe est lu sans sourcer le .env : une espace ou un « $ » dans la
# valeur serait sinon interprété par le shell.
sql_unison() {
    _unison_cd || return 1

    local pass name
    pass=$(sed -n 's/^[[:space:]]*DB_ROOTPASS=//p' "$UNISON_ENV" | head -1 | tr -d '\r"')
    name=$(sed -n 's/^[[:space:]]*DB_NAME=//p' "$UNISON_ENV" | head -1 | tr -d '\r"')

    _unison_dc exec -e MYSQL_PWD="$pass" db mariadb -u root "$name" "$@"
}

# Sauvegarde compressée de la base, datée, dans ~/sauvegardes_unison/.
# À lancer avant toute opération d'écriture faite à la main.
backup_unison() {
    _unison_cd || return 1

    local dossier="$HOME/sauvegardes_unison"
    local cible="$dossier/unison_$(date +%Y%m%d_%H%M%S).sql.gz"
    local pass name

    mkdir -p "$dossier"
    pass=$(sed -n 's/^[[:space:]]*DB_ROOTPASS=//p' "$UNISON_ENV" | head -1 | tr -d '\r"')
    name=$(sed -n 's/^[[:space:]]*DB_NAME=//p' "$UNISON_ENV" | head -1 | tr -d '\r"')

    if _unison_dc exec -T -e MYSQL_PWD="$pass" db \
           mariadb-dump -u root --single-transaction --routines "$name" | gzip > "$cible"; then
        echo "✓ Sauvegarde : $cible ($(du -h "$cible" | cut -f1))"
    else
        echo "✗ Sauvegarde en échec" >&2
        rm -f "$cible"
        return 1
    fi
}
