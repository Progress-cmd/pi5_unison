<?php
/**
 * Rattrapage des formes d'onde manquantes.
 *
 *   docker compose -f docker/docker-compose-dev.yml exec app \
 *       php /var/www/html/src/includes/calculerOndes.php
 *
 * Sert une fois, après la migration 011 : les titres importés avant elle n'ont
 * pas d'onde. Les imports suivants la calculent d'eux-mêmes.
 *
 * Relançable sans risque : seuls les titres dont l'onde manque sont traités.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ondeAudio.php';

$pdo = Config::getConnection();
echo "Base ciblée : " . Config::nomBase() . "\n";

$titres = $pdo->query(
    "SELECT id, title, file FROM tracks WHERE onde IS NULL ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$titres) {
    exit("Toutes les ondes sont déjà calculées.\n");
}

printf("%d titre(s) sans onde.\n\n", count($titres));

$ok = 0;
$echecs = [];
$debut = microtime(true);

foreach ($titres as $t) {
    $chemin = Config::cheminMusiques() . $t['file'];

    if (enregistrerOnde($pdo, (int) $t['id'], $chemin)) {
        $ok++;
        echo '.';
    } else {
        // Fichier absent ou illisible : on le nomme en fin de course plutôt
        // que d'interrompre le rattrapage.
        $echecs[] = '#' . $t['id'] . ' ' . $t['title'];
        echo '!';
    }

    if (($ok + count($echecs)) % 50 === 0) echo "\n";
}

printf("\n\n%d onde(s) calculée(s) en %.1f s.\n", $ok, microtime(true) - $debut);

if ($echecs) {
    printf("%d échec(s) — fichier absent ou illisible :\n", count($echecs));
    foreach ($echecs as $e) {
        echo "  $e\n";
    }
}
