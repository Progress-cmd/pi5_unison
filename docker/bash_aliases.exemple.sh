# Alias de gestion d'Unison — à recopier dans ~/.bash_aliases sur le Pi.
#
#   cp docker/bash_aliases.exemple.sh ~/.bash_aliases
#   . ~/.bash_aliases
#
# Ces fonctions ne sont que des raccourcis vers la commande « unison », qui
# porte toute la logique (docker/unison.sh). C'est volontaire : deux
# implémentations des mêmes opérations finiraient par diverger, et c'est
# toujours celle qu'on avait oubliée qui est utilisée le jour où ça compte.
#
# La commande « unison » seule ouvre un menu interactif ; « unison aide »
# donne la liste complète des sous-commandes.
#
# PRÉREQUIS : le lien symbolique vers le script du dépôt.
#   sudo ln -sfn /home/Francis/apps/pi5_unison/docker/unison.sh /usr/local/bin/unison
#
# Le lien plutôt qu'une copie : le dépôt reste la seule source, et un
# « git pull » met la commande à jour tout seul.

# Emplacement du dépôt. Défini seulement s'il ne l'est pas déjà : exporter
# UNISON_DEPOT avant de charger ce fichier permet de le déplacer sans éditer
# les alias.
UNISON_DEPOT="${UNISON_DEPOT:-/home/Francis/apps/pi5_unison}"

# Repli si le lien dans /usr/local/bin n'a pas été posé : on appelle alors le
# script directement dans le dépôt. Les alias fonctionnent donc dès le premier
# jour, avant même l'installation.
_unison() {
    if command -v unison >/dev/null 2>&1; then
        command unison "$@"
    elif [ -x "$UNISON_DEPOT/docker/unison.sh" ]; then
        "$UNISON_DEPOT/docker/unison.sh" "$@"
    else
        echo "✗ unison introuvable — dépôt attendu dans $UNISON_DEPOT" >&2
        return 1
    fi
}

# --- Déploiement, du plus léger au plus lourd ---
# reload  : le code PHP a changé, rien d'autre       — sans coupure
# update  : + migrations de schéma                    — sans coupure
# upgrade : + reconstruction de l'image (--build)     — coupure de service
#
# En cas de doute : upgrade_unison. Plus lent, jamais faux.
reload_unison()  { _unison reload  "$@"; }
update_unison()  { _unison update  "$@"; }
upgrade_unison() { _unison upgrade "$@"; }

# --- Diagnostic ---
status_unison()     { _unison status   "$@"; }
migrations_unison() { _unison migrations "$@"; }
journal_unison()    { _unison journal  "$@"; }
logs_unison()       { _unison logs     "$@"; }

# --- Exploitation ---
backup_unison() { _unison backup "$@"; }
sql_unison()    { _unison sql    "$@"; }
shell_unison()  { _unison shell  "$@"; }

# --- Mise à jour automatique (cron) ---
# « cron_unison » sans argument affiche l'état ; on / off / toggle pour agir.
# Utile avant une intervention : le cron peut sinon relancer un pull ou une
# reconstruction au milieu d'une manipulation.
cron_unison() { _unison cron "$@"; }
