<?php
/**
 * Diagnostic et réparation des images manquantes ou mortes.
 *
 * À lancer dans le conteneur applicatif :
 *
 *   docker compose exec app php /var/www/html/src/includes/reparerImages.php
 *   docker compose exec app php /var/www/html/src/includes/reparerImages.php --appliquer
 *
 * Sans --appliquer, le script ne fait que rapporter : rien n'est écrit.
 *
 * Il traite deux cas :
 *   - les artistes sans photo (les imports faits en production n'en avaient
 *     aucune, allow_url_fopen y étant désactivé) ;
 *   - les titres dont la miniature est vide ou ne répond plus.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require_once __DIR__ . '/artistImage.php';
require_once __DIR__ . '/config.php';

$appliquer = in_array('--appliquer', $argv ?? [], true);
$pdo = Config::getConnection();

echo $appliquer
    ? "Mode réparation : les corrections seront enregistrées.\n\n"
    : "Mode diagnostic : rien ne sera modifié (ajoutez --appliquer pour corriger).\n\n";

// ------------------------------------------------------------- ARTISTES

$artistes = $pdo->query("SELECT id, name, img FROM artists ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$sansPhoto = array_filter($artistes, fn($a) => empty($a['img']));

printf("ARTISTES : %d au total, %d sans photo\n", count($artistes), count($sansPhoto));

$corrigesArtistes = 0;
$introuvables = [];

foreach ($sansPhoto as $artiste) {
    $img = fetchArtistImage($artiste['name']);

    if (!$img) {
        $introuvables[] = $artiste['name'];
        continue;
    }

    $corrigesArtistes++;
    if ($appliquer) {
        $req = $pdo->prepare("UPDATE artists SET img = :img WHERE id = :id");
        $req->execute([':img' => $img, ':id' => $artiste['id']]);
    }
    printf("  %-34s → %s\n", mb_substr($artiste['name'], 0, 34), $appliquer ? 'corrigé' : 'trouvé');

    // Deezer limite les appels : on reste courtois.
    usleep(250000);
}

if ($introuvables) {
    printf("  %d artiste(s) introuvables chez Deezer : %s\n",
        count($introuvables), implode(', ', array_slice($introuvables, 0, 8)));
}

// --------------------------------------------------------------- TITRES

$titres = $pdo->query("SELECT id, title, img, url FROM tracks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

echo "\nTITRES : " . count($titres) . " au total\n";

$corrigesTitres = 0;
$perdus = [];

foreach ($titres as $titre) {
    // Une miniature déjà valide n'est pas retestée inutilement.
    if (!empty($titre['img']) && urlImageValide($titre['img'])) {
        continue;
    }

    $motif = empty($titre['img']) ? 'miniature absente' : 'miniature morte';

    // L'identifiant de la vidéo suffit à reconstruire une miniature :
    // hqdefault existe pour toute vidéo YouTube.
    parse_str(parse_url($titre['url'] ?? '', PHP_URL_QUERY) ?? '', $params);
    $videoId = $params['v'] ?? null;

    $remplacement = null;
    if ($videoId) {
        $candidate = "https://i.ytimg.com/vi/$videoId/hqdefault.jpg";
        if (urlImageValide($candidate)) {
            $remplacement = $candidate;
        }
    }

    if (!$remplacement) {
        $perdus[] = $titre['title'];
        printf("  %-34s %s, sans remplacement\n", mb_substr($titre['title'], 0, 34), $motif);
        continue;
    }

    $corrigesTitres++;
    if ($appliquer) {
        $req = $pdo->prepare("UPDATE tracks SET img = :img WHERE id = :id");
        $req->execute([':img' => $remplacement, ':id' => $titre['id']]);
    }
    printf("  %-34s %s → %s\n", mb_substr($titre['title'], 0, 34), $motif,
        $appliquer ? 'corrigé' : 'réparable');
}

// ---------------------------------------------------------------- BILAN

echo "\n--- Bilan ---\n";
printf("  Artistes %s : %d\n", $appliquer ? 'corrigés' : 'réparables', $corrigesArtistes);
printf("  Titres   %s : %d\n", $appliquer ? 'corrigés' : 'réparables', $corrigesTitres);

if ($perdus) {
    printf("  Titres sans remplacement possible : %d\n", count($perdus));
}

if (!$appliquer && ($corrigesArtistes || $corrigesTitres)) {
    echo "\nRelancez avec --appliquer pour enregistrer ces corrections.\n";
}
