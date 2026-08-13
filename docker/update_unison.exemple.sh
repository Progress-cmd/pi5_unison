#!/bin/bash
#
# Version enrichie de update_unison.sh, à installer sur le Pi à la place de
# l'actuel : /home/Francis/apps/pi5_unison/update_unison.sh
#
# Elle garde le comportement existant — pull automatique chaque minute et
# rechargement gracieux si le code a changé — et ajoute la prise en charge des
# demandes déposées par la section d'administration d'Unison.
#
# Rappel de sécurité : ce script ne lit JAMAIS le contenu de demande.json pour
# en tirer une commande. Il ne s'intéresse qu'au champ « action », comparé à
# deux valeurs connues. Toute évolution qui ferait passer un paramètre depuis
# l'application (branche, tag, commande…) transformerait ce mécanisme en
# exécution de code à distance.
#
# Installation :
#   cp update_unison.exemple.sh /home/Francis/apps/pi5_unison/update_unison.sh
#
# ATTENTION à l'alias upgrade_unison : « docker compose up -d » réutilise
# l'image existante. Tout ce qui est CUIT DANS L'IMAGE — Dockerfile,
# security.ini (open_basedir, disable_functions), php-prod.ini — n'est alors
# jamais mis à jour. Il faut « up -d --build ». C'est ce que fait la branche
# « reconstruire » ci-dessous.
#   chmod +x /home/Francis/apps/pi5_unison/update_unison.sh
#   mkdir -p /home/Francis/apps/pi5_unison/maj
#   sudo chown 33:33 /home/Francis/apps/pi5_unison/maj   # uid de www-data
#
# Le cron reste inchangé :
#   * * * * * /home/Francis/apps/pi5_unison/update_unison.sh

# Pas de « set -e » : une commande en échec sortirait du script AVANT d'avoir
# publié l'état d'erreur, et l'interface resterait bloquée sur « en cours ».
set -uo pipefail

DEPOT=/home/Francis/apps/pi5_unison
COMPOSE="$DEPOT/docker/docker-compose-prod.yml"
ENVFILE="$DEPOT/docker/.env"

MAJ="$DEPOT/maj"
DEMANDE="$MAJ/demande.json"
ETAT="$MAJ/etat.json"

cd "$DEPOT" || exit 1
mkdir -p "$MAJ"

# Publication atomique : l'application peut lire à tout instant.
ecrire_etat() {
    local statut="$1" message="$2"
    local version
    version=$(git -C "$DEPOT" rev-parse --short HEAD 2>/dev/null || echo inconnue)

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
            if git pull --ff-only \
               && docker compose -f "$COMPOSE" --env-file "$ENVFILE" exec -T app apache2ctl graceful; then
                ecrire_etat succes "Application rechargée"
            else
                ecrire_etat echec "Échec du rechargement — voir les journaux"
            fi
            ;;

        reconstruire)
            ecrire_etat en_cours "Reconstruction des conteneurs"
            if git pull --ff-only \
               && docker compose -f "$COMPOSE" --env-file "$ENVFILE" down \
               && docker container prune -f \
               && docker compose -f "$COMPOSE" --env-file "$ENVFILE" up -d --build; then
                ecrire_etat succes "Conteneurs reconstruits"
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
# 2. Comportement automatique habituel : pull, et rechargement si ça a bougé
# ---------------------------------------------------------------------------
SORTIE=$(git pull 2>&1)

if echo "$SORTIE" | grep -q "Already up to date"; then
    # Rien de neuf : on ne réécrit pas l'état, pour ne pas effacer le résultat
    # de la dernière opération réelle affichée dans l'interface.
    exit 0
fi

echo "Changements détectés, rechargement de l'app..."
if docker compose -f "$COMPOSE" --env-file "$ENVFILE" exec -T app apache2ctl graceful; then
    ecrire_etat succes "Mise à jour automatique appliquée"
else
    ecrire_etat echec "Rechargement automatique en échec"
fi
