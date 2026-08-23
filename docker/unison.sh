#!/bin/bash
#
# unison — poste de pilotage d'Unison sur le serveur.
#
#   unison            menu interactif
#   unison <commande> exécution directe (utilisable dans un script ou un cron)
#   unison aide       liste des commandes
#
# INSTALLATION
# ------------
# Par un lien symbolique vers ce fichier, et non par une copie : le dépôt est
# alors la seule source, et un « git pull » met la commande à jour toute seule.
#
#   sudo ln -sfn /home/Francis/apps/pi5_unison/docker/unison.sh /usr/local/bin/unison
#
# Ce script est le SEUL endroit où vivent les opérations serveur ; les alias de
# docker/bash_aliases.exemple.sh ne font que l'appeler. Deux implémentations
# finiraient toujours par diverger.

set -uo pipefail

# ---------------------------------------------------------------------------
# Emplacement du dépôt
# ---------------------------------------------------------------------------
# readlink -f suit le lien symbolique de /usr/local/bin : la racine est donc
# déduite du vrai fichier, où qu'il soit appelé depuis.
SCRIPT=$(readlink -f "${BASH_SOURCE[0]}")
DEPOT=$(cd "$(dirname "$SCRIPT")/.." && pwd)

COMPOSE="$DEPOT/docker/docker-compose-prod.yml"
ENVFILE="$DEPOT/docker/.env"
MIGRATIONS="$DEPOT/docker/appliquer_migrations.sh"

# Ligne de cron gérée par ce script. Le marqueur, et non le chemin, sert à la
# retrouver : il survit à un déplacement du dépôt.
CRON_MARQUEUR="# unison-maj"
CRON_LIGNE="* * * * * $DEPOT/update_unison.sh >> /tmp/unison_maj.log 2>&1 $CRON_MARQUEUR"

# ---------------------------------------------------------------------------
# Affichage
# ---------------------------------------------------------------------------
# Couleurs seulement si la sortie est un terminal : redirigée dans un fichier
# ou un pipe, elle ne doit pas être polluée par des codes d'échappement.
if [ -t 1 ]; then
    GRAS=$'\e[1m'; ROUGE=$'\e[31m'; VERT=$'\e[32m'; JAUNE=$'\e[33m'
    BLEU=$'\e[34m'; GRIS=$'\e[90m'; RAZ=$'\e[0m'
else
    GRAS=''; ROUGE=''; VERT=''; JAUNE=''; BLEU=''; GRIS=''; RAZ=''
fi

titre() { printf '\n%s%s%s\n' "$GRAS$BLEU" "$1" "$RAZ"; }
ok()    { printf '%s✓%s %s\n' "$VERT" "$RAZ" "$1"; }
ko()    { printf '%s✗%s %s\n' "$ROUGE" "$RAZ" "$1" >&2; }
avert() { printf '%s⚠%s %s\n' "$JAUNE" "$RAZ" "$1"; }
info()  { printf '%s  %s%s\n' "$GRIS" "$1" "$RAZ"; }

dc() { docker compose -f "$COMPOSE" --env-file "$ENVFILE" "$@"; }

# Demande une confirmation pour les opérations qui coupent le service.
confirmer() {
    local reponse
    printf '%s%s%s [o/N] ' "$JAUNE" "$1" "$RAZ"
    read -r reponse
    [[ "$reponse" =~ ^[oOyY]$ ]]
}

# Valeur du .env, lue sans sourcer le fichier : une espace ou un « $ » dans un
# mot de passe serait sinon interprété par le shell.
lire_env() {
    local valeur
    valeur=$(sed -n "s/^[[:space:]]*$1=//p" "$ENVFILE" 2>/dev/null | head -1 | tr -d '\r')
    valeur="${valeur%\"}"; valeur="${valeur#\"}"
    valeur="${valeur%\'}"; valeur="${valeur#\'}"
    printf '%s' "$valeur"
}

# ---------------------------------------------------------------------------
# Cron
# ---------------------------------------------------------------------------
# La mise à jour automatique repose sur une ligne de crontab. La désactiver est
# utile pendant une intervention : sans ça, le cron peut relancer un pull ou
# une reconstruction au milieu d'une manipulation.

# etat : « actif », « desactive » ou « absent »
cron_etat() {
    local ligne
    ligne=$(crontab -l 2>/dev/null | grep -F "$CRON_MARQUEUR" | head -1)

    if [ -z "$ligne" ]; then
        echo absent
    elif [[ "$ligne" =~ ^[[:space:]]*# ]]; then
        echo desactive
    else
        echo actif
    fi
}

# Réécrit la crontab à partir de l'entrée standard, après sauvegarde.
# Le reste de la crontab est toujours préservé : on ne remplace jamais que la
# ligne portant le marqueur.
cron_ecrire() {
    local sauvegarde="/tmp/crontab_unison_$(date +%Y%m%d_%H%M%S).bak"
    crontab -l > "$sauvegarde" 2>/dev/null

    # « crontab - » et non « crontab <fichier> » : la commande crontab tronque
    # les noms de fichiers longs, et remplacerait alors la crontab par le
    # contenu d'un chemin inexistant. L'entrée standard n'a pas cette limite.
    if crontab -; then
        info "crontab précédente sauvegardée : $sauvegarde"
        info "pour revenir en arrière :  crontab - < $sauvegarde"
        return 0
    fi

    ko "Modification de la crontab impossible"
    return 1
}

cron_activer() {
    case "$(cron_etat)" in
        actif)
            ok "La mise à jour automatique est déjà active."
            ;;
        desactive)
            # On retire le « # » de commentaire en tête de la ligne marquée.
            crontab -l 2>/dev/null \
                | sed "\|$CRON_MARQUEUR|s/^[[:space:]]*#[[:space:]]*//" \
                | cron_ecrire && ok "Mise à jour automatique réactivée."
            ;;
        absent)
            if [ ! -x "$DEPOT/update_unison.sh" ]; then
                ko "$DEPOT/update_unison.sh est absent ou non exécutable."
                info "Installez-le avant d'activer le cron :"
                info "  cp docker/update_unison.exemple.sh update_unison.sh && chmod +x update_unison.sh"
                return 1
            fi

            { crontab -l 2>/dev/null; echo "$CRON_LIGNE"; } | cron_ecrire \
                && ok "Mise à jour automatique installée (toutes les minutes)."
            ;;
    esac
}

cron_desactiver() {
    case "$(cron_etat)" in
        absent)
            avert "Aucune ligne de mise à jour automatique dans la crontab."
            ;;
        desactive)
            ok "La mise à jour automatique est déjà désactivée."
            ;;
        actif)
            # Commentée plutôt que supprimée : la réactivation retrouve ainsi
            # la ligne exacte, y compris si elle avait été personnalisée.
            crontab -l 2>/dev/null \
                | sed "\|$CRON_MARQUEUR|s|^|#|" \
                | cron_ecrire && ok "Mise à jour automatique désactivée."
            ;;
    esac
}

cron_basculer() {
    case "$(cron_etat)" in
        actif) cron_desactiver ;;
        *)     cron_activer ;;
    esac
}

cron_afficher() {
    titre "Mise à jour automatique (cron)"
    case "$(cron_etat)" in
        actif)     ok "active — le serveur se met à jour tout seul chaque minute" ;;
        desactive) avert "désactivée — aucune mise à jour automatique" ;;
        absent)    avert "non installée — « unison cron on » pour la mettre en place" ;;
    esac
    crontab -l 2>/dev/null | grep -F "$CRON_MARQUEUR" | sed 's/^/    /'
}

# ---------------------------------------------------------------------------
# État
# ---------------------------------------------------------------------------
cmd_status() {
    titre "Version"
    printf '  %-22s %s\n' "Applicative" \
        "$(sed -n "s/^const UNISON_VERSION = '\(.*\)';/\1/p" "$DEPOT/src/includes/version.php" 2>/dev/null)"
    printf '  %-22s %s\n' "Commit déployé" \
        "$(git -C "$DEPOT" log -1 --format='%h %ad %s' --date=short 2>/dev/null)"
    printf '  %-22s %s\n' "Branche" "$(git -C "$DEPOT" rev-parse --abbrev-ref HEAD 2>/dev/null)"

    titre "Conteneurs"
    dc ps --format '  {{.Service}}\t{{.State}}\t{{.Status}}' 2>/dev/null | column -t -s $'\t' \
        || ko "Docker ne répond pas"

    titre "Schéma"
    if [ -x "$MIGRATIONS" ]; then
        "$MIGRATIONS" --liste 2>&1 | sed 's/^/  /'
    else
        avert "appliquer_migrations.sh absent"
    fi

    cron_afficher

    titre "Disque"
    df -h "$DEPOT" | tail -1 | awk '{printf "  %s utilisés sur %s (%s)\n", $3, $2, $5}'
}

# ---------------------------------------------------------------------------
# Déploiement
# ---------------------------------------------------------------------------
cmd_reload() {
    titre "Rechargement (code PHP seulement)"
    git -C "$DEPOT" pull --ff-only || { ko "git pull en échec"; return 1; }
    dc exec -T app apache2ctl graceful && ok "Application rechargée"
}

cmd_update() {
    titre "Mise à jour (code + schéma, sans coupure)"
    git -C "$DEPOT" pull || { ko "git pull en échec"; return 1; }

    if [ -x "$MIGRATIONS" ]; then
        "$MIGRATIONS" || avert "Migrations en échec — le schéma est peut-être incomplet"
    fi

    dc exec -T app apache2ctl graceful && ok "Unison mis à jour"
}

cmd_upgrade() {
    titre "Reconstruction complète"
    avert "Le service sera interrompu plusieurs minutes."
    confirmer "Continuer ?" || { info "Annulé."; return 0; }

    git -C "$DEPOT" pull || { ko "git pull en échec"; return 1; }

    dc down
    docker container prune -f

    # « --build » est tout l'intérêt de cette commande : sans lui, l'image et
    # donc vendor/, les .ini et la configuration Apache resteraient inchangés.
    dc up -d --build || { ko "Reconstruction en échec"; return 1; }

    [ -x "$MIGRATIONS" ] && { "$MIGRATIONS" || avert "Migrations en échec"; }

    # Le conteneur neuf repart du binaire figé dans l'image, dont la couche
    # Docker est mise en cache : elle peut dater de plusieurs mois. yt-dlp s'y
    # retrouvait périmé et les téléchargements échouaient en 403 — sans que
    # rien ne l'indique, puisque la récupération des métadonnées, elle,
    # continuait de fonctionner. On rattrape donc systématiquement ici.
    cmd_ytdlp auto

    ok "Unison reconstruit et redémarré"
}

cmd_migrations() {
    [ -x "$MIGRATIONS" ] || { ko "appliquer_migrations.sh absent"; return 1; }

    titre "Migrations en attente"
    "$MIGRATIONS" --liste

    if "$MIGRATIONS" --liste 2>/dev/null | grep -q "en attente :"; then
        confirmer "Les appliquer maintenant ?" && "$MIGRATIONS"
    fi
}

# ---------------------------------------------------------------------------
# Exploitation
# ---------------------------------------------------------------------------
cmd_backup() {
    local dossier="${UNISON_SAUVEGARDES:-$HOME/sauvegardes_unison}"
    local cible="$dossier/unison_$(date +%Y%m%d_%H%M%S).sql.gz"

    mkdir -p "$dossier"
    titre "Sauvegarde de la base"

    if dc exec -T -e MYSQL_PWD="$(lire_env DB_ROOTPASS)" db \
           mariadb-dump -u root --single-transaction --routines "$(lire_env DB_NAME)" \
           2>/dev/null | gzip > "$cible" && [ -s "$cible" ]; then
        ok "$cible ($(du -h "$cible" | cut -f1))"
    else
        ko "Sauvegarde en échec"
        rm -f "$cible"
        return 1
    fi

    # Les sauvegardes s'accumulent : on garde les dix dernières.
    local surplus
    surplus=$(ls -1t "$dossier"/unison_*.sql.gz 2>/dev/null | tail -n +11)
    if [ -n "$surplus" ]; then
        echo "$surplus" | xargs rm -f
        info "$(wc -l <<< "$surplus") ancienne(s) sauvegarde(s) supprimée(s)"
    fi
}

# Met à jour yt-dlp dans le conteneur en cours.
#
# Nécessaire parce qu'une reconstruction ne suffit PAS : le Dockerfile
# télécharge yt-dlp dans une couche qui ne change jamais, donc Docker la
# réutilise depuis son cache et réinstalle la même version, même avec --build.
# Or YouTube modifie régulièrement son format, et un yt-dlp en retard échoue
# sur tous les imports à la fois.
#
# La mise à jour se fait dans le conteneur en cours : elle est donc perdue à la
# prochaine reconstruction, où il faudra la relancer.
# Attend que le conteneur applicatif accepte des commandes.
# « docker compose up -d » rend la main dès le conteneur créé, bien avant
# qu'Apache et l'indexation Meilisearch soient prêts : sans cette attente, la
# mise à jour de yt-dlp qui suit échouait sur « service app is not running ».
attendre_app() {
    local essais=${1:-30}

    while [ "$essais" -gt 0 ]; do
        dc exec -T app true >/dev/null 2>&1 && return 0
        essais=$((essais - 1))
        sleep 2
    done

    return 1
}

# Dernière version publiée, lue dans l'URL de redirection de GitHub : quelques
# octets, au lieu des ~30 Mo du binaire. Chaîne vide si GitHub est injoignable.
ytdlp_derniere_version() {
    curl -fsSLI -o /dev/null -w '%{url_effective}' --connect-timeout 5 --max-time 15 \
        https://github.com/yt-dlp/yt-dlp/releases/latest 2>/dev/null \
        | sed -n 's#.*/tag/##p'
}

# $1 = "auto" pour un appel automatique : silencieux quand il n'y a rien à faire,
# et jamais bloquant. Sans argument, c'est l'appel manuel « unison ytdlp ».
cmd_ytdlp() {
    local auto=${1:-}

    [ "$auto" = auto ] || titre "Mise à jour de yt-dlp"

    if ! attendre_app; then
        avert "Conteneur applicatif indisponible — yt-dlp inchangé."
        return 1
    fi

    local avant
    avant=$(dc exec -T app /usr/local/bin/yt-dlp --version 2>/dev/null | tr -d '\r')
    [ "$auto" = auto ] || info "version actuelle : ${avant:-inconnue}"

    # Comparaison préalable : inutile de retélécharger 30 Mo à chaque
    # démarrage des conteneurs si la version en place est déjà la bonne.
    local dernier
    dernier=$(ytdlp_derniere_version)

    if [ -z "$dernier" ]; then
        if [ "$auto" = auto ]; then
            avert "yt-dlp : version publiée non vérifiable (réseau ?) — binaire inchangé"
            return 0
        fi
        avert "Impossible de joindre GitHub — tentative de téléchargement quand même."
    elif [ "$dernier" = "$avant" ]; then
        [ "$auto" = auto ] || ok "Déjà à jour ($avant)"
        return 0
    else
        [ "$auto" = auto ] && titre "Mise à jour de yt-dlp ($avant → $dernier)"
    fi

    # -u root : en production le conteneur tourne en www-data, qui ne peut pas
    # écrire dans /usr/local/bin.
    if dc exec -T -u root app sh -c \
        'curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
             -o /usr/local/bin/yt-dlp.neuf \
         && chmod 755 /usr/local/bin/yt-dlp.neuf \
         && /usr/local/bin/yt-dlp.neuf --version >/dev/null \
         && mv /usr/local/bin/yt-dlp.neuf /usr/local/bin/yt-dlp'; then

        local apres
        apres=$(dc exec -T app /usr/local/bin/yt-dlp --version 2>/dev/null | tr -d '\r')

        if [ "$avant" = "$apres" ]; then
            ok "Déjà à jour ($apres)"
        else
            ok "yt-dlp $avant → $apres"
        fi
        # « upgrade » et « start » relancent désormais cette mise à jour
        # eux-mêmes : le binaire ne peut plus revenir en arrière sans qu'on
        # s'en aperçoive. L'avertissement ne vaut que pour un docker compose
        # lancé à la main, hors de cet outil.
        info "Écrit dans le conteneur en cours ; une reconstruction lancée hors « unison » le remplacerait."
    else
        # Le binaire n'est remplacé qu'après vérification : en cas d'échec,
        # l'ancien yt-dlp est toujours en place et les imports continuent.
        ko "Mise à jour impossible — l'ancienne version reste en place"
        dc exec -T -u root app rm -f /usr/local/bin/yt-dlp.neuf 2>/dev/null
        return 1
    fi
}

cmd_logs()  { dc logs --tail "${1:-80}" -f app; }
cmd_shell() { dc exec app bash; }
cmd_sql()   { dc exec -e MYSQL_PWD="$(lire_env DB_ROOTPASS)" db mariadb -u root "$(lire_env DB_NAME)"; }

cmd_restart() {
    titre "Redémarrage des conteneurs"
    dc restart && ok "Conteneurs redémarrés"
}

cmd_start() {
    titre "Démarrage"
    dc up -d || { ko "Démarrage en échec"; return 1; }
    # « stop » supprime les conteneurs : celui-ci est neuf, donc reparti du
    # yt-dlp de l'image. Même rattrapage que pour « upgrade ».
    cmd_ytdlp auto
    ok "Conteneurs démarrés"
}

cmd_stop() {
    titre "Arrêt des conteneurs"
    confirmer "Unison sera hors service. Continuer ?" || { info "Annulé."; return 0; }
    dc down && ok "Conteneurs arrêtés"
}

# Journal applicatif d'Unison (la table `journal`), à ne pas confondre avec les
# logs Docker : celui-ci contient les connexions, imports et incidents.
cmd_journal() {
    local n="${1:-20}"
    titre "Journal applicatif (${n} derniers événements)"
    dc exec -T -e MYSQL_PWD="$(lire_env DB_ROOTPASS)" db \
        mariadb -u root --table "$(lire_env DB_NAME)" -e \
        "SELECT horodatage, niveau, canal, LEFT(message, 60) AS message, utilisateur
           FROM journal ORDER BY id DESC LIMIT $((n))" 2>/dev/null \
        || ko "Journal illisible (migration 002 appliquée ?)"
}

# ---------------------------------------------------------------------------
# Menu interactif
# ---------------------------------------------------------------------------
resume_entete() {
    local etat_cron conteneurs
    etat_cron=$(cron_etat)
    conteneurs=$(dc ps --status running -q 2>/dev/null | wc -l)

    printf '\n%s┌─ Unison ─────────────────────────────────────────┐%s\n' "$GRAS$BLEU" "$RAZ"
    printf '%s│%s  version %-12s conteneurs actifs : %-6s %s│%s\n' \
        "$GRAS$BLEU" "$RAZ" \
        "$(sed -n "s/^const UNISON_VERSION = '\(.*\)';/\1/p" "$DEPOT/src/includes/version.php" 2>/dev/null)" \
        "$conteneurs" "$GRAS$BLEU" "$RAZ"

    case "$etat_cron" in
        actif)     printf '%s│%s  mise à jour auto : %s%-28s%s %s│%s\n' "$GRAS$BLEU" "$RAZ" "$VERT" "active" "$RAZ" "$GRAS$BLEU" "$RAZ" ;;
        desactive) printf '%s│%s  mise à jour auto : %s%-28s%s %s│%s\n' "$GRAS$BLEU" "$RAZ" "$JAUNE" "DÉSACTIVÉE" "$RAZ" "$GRAS$BLEU" "$RAZ" ;;
        absent)    printf '%s│%s  mise à jour auto : %s%-28s%s %s│%s\n' "$GRAS$BLEU" "$RAZ" "$JAUNE" "non installée" "$RAZ" "$GRAS$BLEU" "$RAZ" ;;
    esac

    printf '%s└──────────────────────────────────────────────────┘%s\n' "$GRAS$BLEU" "$RAZ"
}

menu() {
    local choix
    while true; do
        resume_entete

        cat <<MENU

  ${GRAS}État et déploiement${RAZ}
    1  État complet du serveur
    2  Mettre à jour            (code + schéma, sans coupure)
    3  Reconstruire             (--build, coupure de service)
    4  Recharger                (code PHP seulement)
    5  Migrations de schéma

  ${GRAS}Exploitation${RAZ}
    6  Sauvegarder la base
    7  Mettre à jour yt-dlp     (imports YouTube en échec)
    8  Journal applicatif
    9  Logs Docker (suivi en direct)
   10  Terminal SQL
   11  Shell dans le conteneur

  ${GRAS}Conteneurs${RAZ}
   12  Redémarrer
   13  Démarrer
   14  Arrêter

  ${GRAS}Mise à jour automatique${RAZ}
   15  Activer / désactiver le cron
   16  Détail du cron

    0  Quitter

MENU
        printf '%sChoix :%s ' "$GRAS" "$RAZ"
        read -r choix || break

        case "$choix" in
            1)  cmd_status ;;
            2)  cmd_update ;;
            3)  cmd_upgrade ;;
            4)  cmd_reload ;;
            5)  cmd_migrations ;;
            6)  cmd_backup ;;
            7)  cmd_ytdlp ;;
            8)  printf 'Combien d’événements ? [20] '; read -r n; cmd_journal "${n:-20}" ;;
            9)  info "Ctrl+C pour revenir au menu"; cmd_logs ;;
            10) cmd_sql ;;
            11) cmd_shell ;;
            12) cmd_restart ;;
            13) cmd_start ;;
            14) cmd_stop ;;
            15) cron_basculer ;;
            16) cron_afficher ;;
            0|q|Q) exit 0 ;;
            '')  ;;
            *)  ko "Choix inconnu : $choix" ;;
        esac

        printf '\n%s— Entrée pour revenir au menu —%s' "$GRIS" "$RAZ"
        read -r _
    done
}

# ---------------------------------------------------------------------------
# Aide
# ---------------------------------------------------------------------------
cmd_aide() {
    cat <<AIDE
${GRAS}unison${RAZ} — pilotage du serveur Unison

  ${GRAS}unison${RAZ}                  menu interactif

  ${GRAS}État${RAZ}
    status               version, conteneurs, schéma, cron, disque
    journal [n]          n derniers événements du journal applicatif
    logs [n]             logs Docker de l'application, en direct

  ${GRAS}Déploiement${RAZ}
    update               pull + migrations + rechargement (sans coupure)
    upgrade              pull + reconstruction --build (coupure)
    reload               pull + rechargement du code PHP seulement
    migrations           migrations en attente, puis application

  ${GRAS}Exploitation${RAZ}
    backup               sauvegarde compressée de la base
    sql                  client SQL sur la base principale
    ytdlp                met à jour yt-dlp (imports YouTube en échec)
    shell                shell dans le conteneur applicatif

  ${GRAS}Conteneurs${RAZ}
    start | stop | restart

  ${GRAS}Mise à jour automatique${RAZ}
    cron                 état de la ligne de crontab
    cron on | off        activer / désactiver
    cron toggle          basculer

En cas de doute entre update et upgrade : upgrade est plus lent, jamais faux.
AIDE
}

# ---------------------------------------------------------------------------
# Aiguillage
# ---------------------------------------------------------------------------
[ -d "$DEPOT/docker" ] || { ko "Dépôt Unison introuvable depuis $SCRIPT"; exit 1; }

case "${1:-}" in
    '')                 menu ;;
    status|etat)        cmd_status ;;
    update|maj)         cmd_update ;;
    upgrade|rebuild)    cmd_upgrade ;;
    reload)             cmd_reload ;;
    migrations|migrate) cmd_migrations ;;
    backup|sauvegarde)  cmd_backup ;;
    logs)               cmd_logs "${2:-80}" ;;
    ytdlp)              cmd_ytdlp ;;
    journal)            cmd_journal "${2:-20}" ;;
    sql)                cmd_sql ;;
    shell|bash)         cmd_shell ;;
    restart)            cmd_restart ;;
    start)              cmd_start ;;
    stop)               cmd_stop ;;
    cron)
        case "${2:-}" in
            on|activer)     cron_activer ;;
            off|desactiver) cron_desactiver ;;
            toggle|bascule) cron_basculer ;;
            '')             cron_afficher ;;
            *)              ko "Usage : unison cron [on|off|toggle]"; exit 1 ;;
        esac
        ;;
    aide|help|-h|--help) cmd_aide ;;
    *)
        ko "Commande inconnue : $1"
        cmd_aide
        exit 1
        ;;
esac
