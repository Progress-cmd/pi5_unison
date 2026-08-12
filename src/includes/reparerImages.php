<?php
/**
 * Diagnostic et réparation des images manquantes ou mortes.
 *
 * Deux points d'entrée pour la même logique :
 *
 *   - en ligne de commande (bloc en bas de fichier) :
 *       docker compose exec app php /var/www/html/src/includes/reparerImages.php
 *       docker compose exec app php /var/www/html/src/includes/reparerImages.php --appliquer
 *
 *   - depuis la section d'administration, via actions/admin/reparer_images.php,
 *     qui appelle reparerImages() et affiche le rapport.
 *
 * En mode diagnostic, rien n'est écrit.
 *
 * Deux cas traités :
 *   - les artistes sans photo (les imports faits en production n'en avaient
 *     aucune, allow_url_fopen y étant désactivé) ;
 *   - les titres dont la miniature est vide ou ne répond plus.
 */

require_once __DIR__ . '/artistImage.php';
require_once __DIR__ . '/config.php';

/**
 * Analyse et, si demandé, corrige les images.
 *
 * @param bool $appliquer false pour un simple diagnostic.
 * @return array{rapport:string, artistes:int, titres:int, perdus:int}
 */
function reparerImages(PDO $pdo, bool $appliquer): array
{
    $lignes = [];
    $ecrire = function (string $texte) use (&$lignes) { $lignes[] = $texte; };

    $ecrire($appliquer
        ? "Mode réparation : les corrections sont enregistrées."
        : "Mode diagnostic : rien n'est modifié.");
    $ecrire('');

    // ------------------------------------------------------------- ARTISTES

    $artistes = $pdo->query("SELECT id, name, img FROM artists ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $sansPhoto = array_filter($artistes, fn($a) => empty($a['img']));

    $ecrire(sprintf('ARTISTES : %d au total, %d sans photo', count($artistes), count($sansPhoto)));

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
        $ecrire(sprintf('  %-34s → %s', mb_substr($artiste['name'], 0, 34), $appliquer ? 'corrigé' : 'trouvé'));

        // Deezer limite les appels : on reste courtois.
        usleep(250000);
    }

    if ($introuvables) {
        $ecrire(sprintf('  %d artiste(s) introuvables chez Deezer : %s',
            count($introuvables), implode(', ', array_slice($introuvables, 0, 8))));
    }

    // --------------------------------------------------------------- TITRES

    $titres = $pdo->query("SELECT id, title, img, url FROM tracks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $ecrire('');
    $ecrire('TITRES : ' . count($titres) . ' au total');

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
            $ecrire(sprintf('  %-34s %s, sans remplacement', mb_substr($titre['title'], 0, 34), $motif));
            continue;
        }

        $corrigesTitres++;
        if ($appliquer) {
            $req = $pdo->prepare("UPDATE tracks SET img = :img WHERE id = :id");
            $req->execute([':img' => $remplacement, ':id' => $titre['id']]);
        }
        $ecrire(sprintf('  %-34s %s → %s', mb_substr($titre['title'], 0, 34), $motif,
            $appliquer ? 'corrigé' : 'réparable'));
    }

    // ---------------------------------------------------------------- BILAN

    $ecrire('');
    $ecrire('--- Bilan ---');
    $ecrire(sprintf('  Artistes %s : %d', $appliquer ? 'corrigés' : 'réparables', $corrigesArtistes));
    $ecrire(sprintf('  Titres   %s : %d', $appliquer ? 'corrigés' : 'réparables', $corrigesTitres));

    if ($perdus) {
        $ecrire(sprintf('  Titres sans remplacement possible : %d', count($perdus)));
    }

    if (!$appliquer && ($corrigesArtistes || $corrigesTitres)) {
        $ecrire('');
        $ecrire('Relancez en mode réparation pour enregistrer ces corrections.');
    }

    return [
        'rapport'  => implode("\n", $lignes),
        'artistes' => $corrigesArtistes,
        'titres'   => $corrigesTitres,
        'perdus'   => count($perdus),
    ];
}

// --------------------------------------------------------- POINT D'ENTRÉE CLI
// Ne s'exécute que lancé directement en ligne de commande : un include depuis
// une action web ne déclenche rien.

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $resultat = reparerImages(Config::getConnection(), in_array('--appliquer', $argv, true));
    echo $resultat['rapport'] . "\n";
}
