#!/bin/bash
#
# Installe (ou réinstalle) l'environnement de démonstration d'Unison.
#
#   ./demo_data/installer.sh            # sur le conteneur de développement
#   COMPOSE=docker/docker-compose-prod.yml ./demo_data/installer.sh
#
# L'opération est rejouable : elle remet la démonstration dans son état
# d'origine, ce qui est la façon prévue de la nettoyer après une présentation.
#
# Elle crée trois choses, toutes séparées de l'application :
#   1. la base <DB_NAME>_demo, avec son contenu fictif ;
#   2. les fichiers audio de démonstration, générés à la synthèse ;
#   3. les index Meilisearch suffixés « _demo ».
#
set -euo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="${COMPOSE:-docker/docker-compose-dev.yml}"
NB_TITRES=16
DUREE=42

cd "$RACINE"

set -a
# shellcheck disable=SC1091
. docker/.env
set +a

DB_DEMO="${DB_NAME_DEMO:-${DB_NAME}_demo}"

# --- Garde-fous : ce script fait un DROP DATABASE ---
# Il ne doit jamais pouvoir viser la base de l'application, quoi qu'on ait
# mis dans le .env. Une variable mal renseignée détruirait les vraies données.
if [ -z "${DB_NAME:-}" ]; then
    echo "Abandon : DB_NAME n'est pas défini dans docker/.env." >&2
    exit 1
fi

if [ "$DB_DEMO" = "$DB_NAME" ]; then
    echo "Abandon : la base de démonstration porte le même nom que la base de" >&2
    echo "l'application (« $DB_NAME »). Corrigez DB_NAME_DEMO dans docker/.env." >&2
    exit 1
fi

case "$DB_DEMO" in
    *_demo) ;;
    *)
        echo "Abandon : par sécurité, la base de démonstration doit se terminer" >&2
        echo "par « _demo » (reçu : « $DB_DEMO »)." >&2
        exit 1
        ;;
esac

dc() { docker compose -f "$COMPOSE" "$@"; }
sql_root() { dc exec -T db mariadb -u root -p"$DB_ROOTPASS" "$@"; }

echo "→ Base de démonstration « $DB_DEMO » (l'application utilise « $DB_NAME »)"

# La base est recréée de zéro : c'est ce qui rend le script rejouable.
sql_root -e "DROP DATABASE IF EXISTS \`$DB_DEMO\`;
             CREATE DATABASE \`$DB_DEMO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
             GRANT ALL PRIVILEGES ON \`$DB_DEMO\`.* TO '$DB_USER'@'%';
             FLUSH PRIVILEGES;"

sql_root "$DB_DEMO" < demo_data/structure.sql
sql_root "$DB_DEMO" < demo_data/donnees.sql
echo "  structure et contenu fictif chargés"

echo "→ Fichiers audio de démonstration"

# Nappes de synthèse générées par ffmpeg : aucun enregistrement de tiers n'est
# distribué, la démonstration est donc diffusable publiquement sans réserve.
dc exec -T app bash -c "
    set -e
    mkdir -p /var/www/music_data/demo
    rm -f /var/www/music_data/demo/demo_*.mp3

    for i in \$(seq 1 $NB_TITRES); do
        nom=\$(printf 'demo_%02d.mp3' \"\$i\")

        # Deux fréquences par titre : la fondamentale monte d'un cran à chaque
        # morceau, la seconde ajoute une quinte, pour que les pistes se
        # distinguent à l'oreille pendant la démonstration.
        f1=\$((160 + i * 18))
        f2=\$((f1 * 3 / 2))

        ffmpeg -loglevel error -y \
            -f lavfi -i \"sine=frequency=\$f1:duration=$DUREE\" \
            -f lavfi -i \"sine=frequency=\$f2:duration=$DUREE\" \
            -filter_complex \"[0:a][1:a]amix=inputs=2,tremolo=f=0.25:d=0.7,volume=0.4,afade=t=in:d=3,afade=t=out:st=\$(($DUREE - 4)):d=4[out]\" \
            -map '[out]' -c:a libmp3lame -b:a 128k \
            \"/var/www/music_data/demo/\$nom\"
    done

    chown -R www-data:www-data /var/www/music_data/demo
    echo \"  \$(ls /var/www/music_data/demo/*.mp3 | wc -l) fichiers générés\"
"

echo "→ Index Meilisearch"
dc exec -T app php /var/www/html/src/includes/initSearch.php | sed 's/^/  /'

echo
echo "Démonstration prête. Bouton « Découvrir la démo » sur la page de connexion."
