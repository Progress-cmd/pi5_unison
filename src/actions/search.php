<?php
include_once "../includes/auth.php";
exigerConnexion(true);
require '../../vendor/autoload.php';
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;

$q = filter_input(INPUT_POST, 'search-entry', FILTER_DEFAULT);
$playlist_id = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);

if (empty($q)) {
    echo json_encode([]);
    exit;
}

include_once "../includes/config.php";

$meiliKey = getenv('MS_PASS') ?? null;
$client = new Client('http://ms:7700', $meiliKey);

// Index dédiés en démonstration : la recherche ne doit pas non plus laisser
// filtrer un titre ou un artiste du catalogue personnel.
$resultatsMusiques = $client->index(Config::indexMeili('musiques'))->search($q)->getHits();
$resultatsArtists  = $client->index(Config::indexMeili('artists'))->search($q)->getHits();

// Si un playlist_id est fourni, vérifie quelles tracks sont déjà dedans
if ($playlist_id) {
    $pdo = Config::getConnection();

    // Récupère tous les track_id déjà dans la playlist en une seule requête
    $req = $pdo->prepare("SELECT track_id FROM track__playlist WHERE playlist_id = :playlist");
    $req->execute([':playlist' => $playlist_id]);
    $dejaDedans = array_flip($req->fetchAll(PDO::FETCH_COLUMN)); // flip pour isset() rapide

    // Ajoute le flag in_playlist sur chaque musique
    $resultatsMusiques = array_map(function($hit) use ($dejaDedans) {
        $hit['in_playlist'] = isset($dejaDedans[$hit['id_music']]) ? 1 : 0;
        return $hit;
    }, $resultatsMusiques);
}

// Retour JSON
echo json_encode([
    'musiques' => $resultatsMusiques,
    'artistes' => $resultatsArtists,
]);