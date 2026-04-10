#!/bin/bash
set -e

echo "Services prêts, lancement de l'indexation Meilisearch..."
php /var/www/html/src/includes/initSearch.php

echo "Démarrage Apache..."
exec apache2-foreground