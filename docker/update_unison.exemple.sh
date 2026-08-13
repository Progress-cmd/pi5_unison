#!/bin/bash
#
# Script de mise à jour d'Unison, lancé chaque minute par le cron de l'hôte.
# À installer sur le Pi : /home/Francis/apps/pi5_unison/update_unison.sh
#
# Il fait trois choses :
#   1. il traite les demandes déposées par la section d'administration ;
#   2. il applique les migrations de schéma en attente ;
#   3. il décide seul entre un rechargement gracieux et une reconstruction.
#
# CE QUE CE SCRIPT CORRIGE
# ------------------------
# Un « git pull » suivi d'un « apache2ctl graceful » ne suffit pas toujours,
# et les deux cas où il ne suffit pas sont silencieux — le déploiement paraît
# réussi, la fonctionnalité manque :
#
#   · Ce qui est CUIT DANS L'IMAGE n'est pas rechargé. src/ est monté en
#     volume, donc le code PHP s'applique tout de suite ; mais vendor/ (donc
#     composer.json et composer.lock), les fichiers docker/*.ini —
#     security.ini, open_basedir, disable_functions —, 000-default.conf, le
#     Dockerfile et meilisearch_init/init.sh sont copiés à la construction.
#     Les mettre à jour exige « up -d --build » : un « up -d » seul réutilise
#     l'image existante. C'est la détection de la section 3.
#
#   · Le SCHÉMA DE LA BASE ne bouge pas. mysql_init/ n'est rejoué par Docker
#     que sur une base vierge : sur une installation en service, la table
#     qu'attend une nouvelle fonctionnalité n'est jamais créée. D'où l'appel à
#     appliquer_migrations.sh, systématique après chaque pull.
#
# RAPPEL DE SÉCURITÉ
# ------------------
# Ce script ne lit JAMAIS le contenu de demande.json pour en tirer une
# commande. Il n'en extrait que le champ « action », comparé à deux valeurs
# connues. Toute évolution qui ferait passer un paramètre depuis l'application
# (branche, tag, commande…) transformerait ce mécanisme en exécution de code à
# distance.
#
# Installation :
#   cp docker/update_unison.exemple.sh /home/Francis/apps/pi5_unison/update_unison.sh
#   chmod +x /home/Francis/apps/pi5_unison/update_unison.sh
#   chmod +x /home/Francis/apps/pi5_unison/docker/appliquer_migrations.sh
#   mkdir -p /home/Francis/apps/pi5_unison/maj
#   sudo chown 33:33 /home/Francis/apps/pi5_unison/maj   # uid de www-data
#
# Le cron reste inchangé :
#   * * * * * /home/Francis/apps/pi5_unison/update_unison.sh >> /var/log/unison_maj.log 2>&1

# Pas de « set -e » : une commande en échec sortirait du script AVANT d'avoir
# publié l'état d'erreur, et l'interface resterait bloquée sur « en cours ».
set -uo pipefail

DEPOT=/home/Francis/apps/pi5_unison
COMPOSE="$DEPOT/docker/docker-compose-prod.yml"
ENVFILE="$DEPOT/docker/.env"
MIGRATIONS="$DEPOT/docker/appliquer_migrations.sh"

MAJ="$DEPOT/maj"
DEMANDE="$MAJ/demande.json"
ETAT="$MAJ/etat.json"

# Reconstruire automatiquement quand un fichier de l'image a changé.
# À passer à 0 pour n'être jamais interrompu sans l'avoir demandé : le script
# se contentera alors de PRÉVENIR qu'une reconstruction est nécessaire, et le
# bouton « Reconstruire » de la page Maintenance restera à actionner à la main.
RECONSTRUCTION_AUTO=1

cd "$DEPOT" || exit 1
mkdir -p "$MAJ"

dc() { docker compose -f "$COMPOSE" --env-file "$ENVFILE" "$@"; }

# Publication atomique : l'application peut lire à tout instant.
ecrire_etat() {
    local statut="$1" message="$2"
    local version
    version=$(git -C "$DEPOT" rev-parse --short HEAD 2>/dev/null || echo inconnue)

    # Les guillemets et antislashes casseraient le JSON produit : le message
    # vient d'ici, mais un message d'erreur repris tel quel pourrait en contenir.
    message=${message//\\/\\\\}
    message=${message//\"/\\\"}

    cat > "$ETAT.tmp" <<JSON
{
  "statut": "$statut",
  "message": "$message",
  "depuis": "$(date -Is)",
  "version": "$version"
}
JSON
    mv "$ETAT.tmp" "$ETAT"
    chmod 664 "$ETAT" 2>/dev/null
}

# Applique les migrations en attente. Ne bloque pas le déploiement en cas
# d'échec : le code est déjà en place, et les pages concernées savent dire que
# leur table manque. On le signale, sans casser le reste.
appliquer_migrations() {
    if [ ! -x "$MIGRATIONS" ]; then
        echo "⚠ $MIGRATIONS absent ou non exécutable — migrations ignorées"
        return 0
    fi

    echo "→ Migrations de schéma"
    if "$MIGRATIONS"; then
        return 0
    fi

    echo "✗ Migrations en échec"
    return 1
}

# Vrai si le diff touche un fichier embarqué dans l'image Docker.
# src/ n'y figure pas : il est monté en volume, un rechargement suffit.
necessite_reconstruction() {
    grep -qE \
        -e '^docker/Dockerfile$' \
        -e '^docker/.*\.ini$' \
        -e '^docker/.*\.conf$' \
        -e '^docker/docker-compose.*\.ya?ml$' \
        -e '^composer\.(json|lock)$' \
        -e '^meilisearch_init/' \
        <<< "$1"
}

# ---------------------------------------------------------------------------
# 1. Demande déposée par l'interface d'administration
# ---------------------------------------------------------------------------
if [ -f "$DEMANDE" ]; then
    # Seule valeur extraite du fichier, et immédiatement contrainte à deux
    # possibilités : rien d'autre n'en sort.
    ACTION=$(grep -o '"action"[[:space:]]*:[[:space:]]*"[a-z]*"' "$DEMANDE" \
             | grep -o '[a-z]*"$' | tr -d '"')

    # La demande est consommée AVANT d'agir : sans ça, un échec la laisserait
    # en place et la mise à jour repartirait à chaque passage du cron.
    rm -f "$DEMANDE"

    case "$ACTION" in
        recharger)
            ecrire_etat en_cours "Rechargement demandé"

            if git pull --ff-only && dc exec -T app apache2ctl graceful; then
                if appliquer_migrations; then
                    ecrire_etat succes "Application rechargée"
                else
                    ecrire_etat echec "Rechargée, mais migration de schéma en échec"
                fi
            else
                ecrire_etat echec "Échec du rechargement — voir les journaux"
            fi
            ;;

        reconstruire)
            ecrire_etat en_cours "Reconstruction des conteneurs"

            if git pull --ff-only \
               && dc down \
               && docker container prune -f \
               && dc up -d --build; then
                # Après un « up », la base met quelques secondes à accepter les
                # connexions : appliquer_migrations.sh l'attend de lui-même.
                if appliquer_migrations; then
                    ecrire_etat succes "Conteneurs reconstruits"
                else
                    ecrire_etat echec "Conteneurs reconstruits, migration en échec"
                fi
            else
                ecrire_etat echec "Échec de la reconstruction — voir les journaux"
            fi
            ;;

        *)
            ecrire_etat echec "Action inconnue dans la demande"
            ;;
    esac

    exit 0
fi

# ---------------------------------------------------------------------------
# 2. Y a-t-il du nouveau ?
# ---------------------------------------------------------------------------
# On compare les commits plutôt que de chercher « Already up to date » dans la
# sortie de git : ce message dépend de la langue et de la version de git, et
# le jour où il change, le script cesse silencieusement de déployer.
AVANT=$(git rev-parse HEAD 2>/dev/null)

if ! git pull --ff-only >/dev/null 2>&1; then
    # Échec de pull : réseau coupé, ou historique divergent (une modification
    # locale sur le Pi). Le second cas demande une intervention.
    ecrire_etat echec "git pull impossible — dépôt divergent ou réseau coupé"
    exit 1
fi

APRES=$(git rev-parse HEAD 2>/dev/null)

if [ "$AVANT" = "$APRES" ]; then
    # Rien de neuf : on ne réécrit pas l'état, pour ne pas effacer le résultat
    # de la dernière opération réelle affichée dans l'interface.
    exit 0
fi

MODIFS=$(git diff --name-only "$AVANT" "$APRES")
echo "Changements détectés ($AVANT → $APRES) :"
sed 's/^/    /' <<< "$MODIFS"

# ---------------------------------------------------------------------------
# 3. Rechargement, ou reconstruction si l'image est concernée
# ---------------------------------------------------------------------------
if necessite_reconstruction "$MODIFS"; then

    if [ "$RECONSTRUCTION_AUTO" -ne 1 ]; then
        # Le code est à jour mais l'image ne l'est pas : c'est exactement la
        # situation qui rend un bug indéchiffrable. On le dit clairement.
        ecrire_etat echec "Reconstruction nécessaire (fichiers d'image modifiés) — à lancer depuis Maintenance"
        echo "⚠ Fichiers embarqués dans l'image modifiés : reconstruction requise."
        dc exec -T app apache2ctl graceful >/dev/null 2>&1
        appliquer_migrations
        exit 0
    fi

    echo "→ Fichiers d'image modifiés : reconstruction"
    ecrire_etat en_cours "Reconstruction automatique en cours"

    if dc up -d --build; then
        if appliquer_migrations; then
            ecrire_etat succes "Mise à jour appliquée avec reconstruction"
        else
            ecrire_etat echec "Reconstruite, mais migration de schéma en échec"
        fi
    else
        ecrire_etat echec "Reconstruction automatique en échec"
    fi

    exit 0
fi

echo "→ Rechargement gracieux"

if dc exec -T app apache2ctl graceful; then
    if appliquer_migrations; then
        ecrire_etat succes "Mise à jour automatique appliquée"
    else
        ecrire_etat echec "Rechargée, mais migration de schéma en échec"
    fi
else
    ecrire_etat echec "Rechargement automatique en échec"
fi
