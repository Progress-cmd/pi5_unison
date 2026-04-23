<?php
require '../../vendor/autoload.php';
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;

$q = filter_input(INPUT_POST, 'search-entry', FILTER_DEFAULT);

if (empty($q)) {
    echo json_encode([]);
    exit;
}

$meiliKey = getenv('MS_PASS') ?? null;
$client = new Client('http://ms:7700', $meiliKey);


// Recherche dans l'index musiques
$indexMusiques = $client->index('musiques');
$resultatsMusiques = $indexMusiques->search($q);

// Recherche dans l'index artists
$indexArtists = $client->index('artists');
$resultatsArtists = $indexArtists->search($q);

// Retour JSON
echo json_encode([
    'musiques' => $resultatsMusiques->getHits(),
    'artistes' => $resultatsArtists->getHits(),
]);