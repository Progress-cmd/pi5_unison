#!/bin/bash
#
# Applique les migrations de schéma en attente sur la base principale.
#
# POURQUOI CE FICHIER EXISTE
# --------------------------
# mysql_init/ n'est rejoué par Docker que sur une base VIERGE. Sur une
# installation en service, un « git pull » apporte le code d'une nouvelle
# fonctionnalité mais pas la table qu'elle attend : la fonctionnalité est
# livrée à moitié, et l'erreur ne se voit qu'à l'usage. Ce script comble ce
# trou, et il est appelé automatiquement par update_unison.sh après chaque
# pull — il n'y a donc rien à penser au moment de déployer.
#
# Les migrations déjà passées sont enregistrées dans la table
# `schema_migrations` : chacune n'est jouée qu'une fois. Elles restent malgré
# tout écrites de façon idempotente (CREATE TABLE IF NOT EXISTS, ADD COLUMN
# IF NOT EXISTS), pour qu'une réexécution — sur une base dont le suivi aurait
# été perdu — soit sans conséquence.
#
# La base de DÉMONSTRATION n'est pas concernée : elle est recréée de zéro
# depuis demo_data/, qui contient déjà le schéma à jour.
#
# Usage :
#   ./docker/appliquer_migrations.sh              # production (défaut)
#   ./docker/appliquer_migrations.sh --dev        # environnement de dev
#   ./docker/appliquer_migrations.sh --liste      # état, sans rien appliquer
#
# Codes de sortie : 0 = à jour ou appliqué, 1 = échec.

set -uo pipefail

# Racine du dépôt, déduite de l'emplacement de ce script : il reste ainsi
# valable quel que soit le dossier depuis lequel on l'appelle.
DEPOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

COMPOSE="$DEPOT/docker/docker-compose-prod.yml"
ENVFILE="$DEPOT/docker/.env"
MIGRATIONS="$DEPOT/mysql_init/migrations"
LISTER_SEULEMENT=0

for argument in "$@"; do
    case "$argument" in
        --dev)    COMPOSE="$DEPOT/docker/docker-compose-dev.yml" ;;
        --liste)  LISTER_SEULEMENT=1 ;;
        *)        echo "Argument inconnu : $argument" >&2; exit 1 ;;
    esac
done

# ---------------------------------------------------------------------------
# Lecture du .env
# ---------------------------------------------------------------------------
# Le fichier n'est PAS sourcé : un mot de passe contenant une espace, un « $ »
# ou un « ` » serait interprété par le shell — au mieux une valeur fausse, au
# pire une commande exécutée. On extrait la ligne telle quelle.
lire_env() {
    local cle="$1" valeur
    valeur=$(sed -n "s/^[[:space:]]*${cle}=//p" "$ENVFILE" | head -1 | tr -d '\r')

    # Guillemets encadrants éventuels, que docker compose accepte aussi.
    valeur="${valeur%\"}"; valeur="${valeur#\"}"
    valeur="${valeur%\'}"; valeur="${valeur#\'}"

    printf '%s' "$valeur"
}

if [ ! -f "$ENVFILE" ]; then
    echo "✗ Fichier d'environnement introuvable : $ENVFILE" >&2
    exit 1
fi

DB_ROOTPASS=$(lire_env DB_ROOTPASS)
DB_NAME=$(lire_env DB_NAME)

if [ -z "$DB_ROOTPASS" ] || [ -z "$DB_NAME" ]; then
    echo "✗ DB_ROOTPASS ou DB_NAME absent de $ENVFILE" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Accès à la base
# ---------------------------------------------------------------------------
# Le mot de passe passe par MYSQL_PWD plutôt que par « -p… » : il n'apparaît
# ainsi pas dans la liste des processus du conteneur, et le client cesse
# d'avertir à chaque appel.
mariadb_exec() {
    docker compose -f "$COMPOSE" --env-file "$ENVFILE" exec -T \
        -e MYSQL_PWD="$DB_ROOTPASS" db \
        mariadb -u root --default-character-set=utf8mb4 "$@"
}

# Requête renvoyant une valeur brute, sans en-tête ni cadre.
sql_valeur() {
    mariadb_exec -N -B "$DB_NAME" -e "$1" 2>/dev/null
}

# ---------------------------------------------------------------------------
# Attente de la base
# ---------------------------------------------------------------------------
# Appelé juste après un « up -d », le conteneur peut répondre avant que
# MariaDB n'accepte les connexions. Sans cette attente, la première migration
# échouerait sur une base pourtant en train de démarrer normalement.
attendre_base() {
    local essais=30

    while [ "$essais" -gt 0 ]; do
        if sql_valeur "SELECT 1" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
        essais=$((essais - 1))
    done

    return 1
}

if ! attendre_base; then
    echo "✗ Base injoignable après 60 s — les conteneurs sont-ils démarrés ?" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Table de suivi
# ---------------------------------------------------------------------------
if ! mariadb_exec "$DB_NAME" -e "
    CREATE TABLE IF NOT EXISTS \`schema_migrations\` (
      \`fichier\` varchar(190) NOT NULL,
      \`applique_le\` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (\`fichier\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
" 2>/dev/null; then
    echo "✗ Impossible de créer la table de suivi des migrations" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
if [ ! -d "$MIGRATIONS" ]; then
    echo "Aucun dossier de migrations ($MIGRATIONS) — rien à faire."
    exit 0
fi

appliquees=0
echecs=0
en_attente=0

for fichier in "$MIGRATIONS"/*.sql; do
    # Le motif non développé signifie « dossier vide ».
    [ -e "$fichier" ] || break

    nom=$(basename "$fichier")

    # Nom contraint : il part dans une requête SQL, et c'est la seule valeur de
    # ce script à le faire. Le format est celui du projet — 002_journal.sql.
    if ! [[ "$nom" =~ ^[0-9]{3}_[A-Za-z0-9_]+\.sql$ ]]; then
        echo "⚠ Ignorée (nom hors convention NNN_nom.sql) : $nom"
        continue
    fi

    deja=$(sql_valeur "SELECT COUNT(*) FROM schema_migrations WHERE fichier = '$nom'")

    if [ "$deja" = "1" ]; then
        continue
    fi

    if [ "$LISTER_SEULEMENT" -eq 1 ]; then
        echo "· en attente : $nom"
        en_attente=$((en_attente + 1))
        continue
    fi

    printf '→ %s ... ' "$nom"

    if mariadb_exec "$DB_NAME" < "$fichier" 2>/tmp/unison_migration_err; then
        # Enregistrée seulement après succès : une migration interrompue sera
        # retentée au prochain passage plutôt que réputée faite.
        sql_valeur "INSERT INTO schema_migrations (fichier) VALUES ('$nom')" >/dev/null
        echo "appliquée"
        appliquees=$((appliquees + 1))
    else
        echo "ÉCHEC"
        sed 's/^/    /' /tmp/unison_migration_err >&2
        echecs=$((echecs + 1))

        # On s'arrête à la première erreur : les migrations sont ordonnées, et
        # jouer la suivante sur un schéma à moitié migré aggraverait les choses.
        break
    fi
done

rm -f /tmp/unison_migration_err

# ---------------------------------------------------------------------------
# Verdict
# ---------------------------------------------------------------------------
if [ "$LISTER_SEULEMENT" -eq 1 ]; then
    if [ "$en_attente" -eq 0 ]; then
        echo "✓ Schéma à jour, aucune migration en attente."
    else
        echo "$en_attente migration(s) en attente."
    fi
    exit 0
fi

if [ "$echecs" -gt 0 ]; then
    echo "✗ Migration en échec — le schéma est peut-être incomplet." >&2
    exit 1
fi

if [ "$appliquees" -eq 0 ]; then
    echo "✓ Schéma déjà à jour."
else
    echo "✓ $appliquees migration(s) appliquée(s)."
fi

exit 0
