<?php
/**
 * Suppression d'un artiste, d'un genre, d'une étiquette ou d'une playlist.
 *
 * Toutes les liaisons sont en ON DELETE CASCADE : la suppression de la ligne
 * suffit. Les titres, eux, ne sont jamais touchés — supprimer un artiste ne
 * supprime pas sa musique, seulement le rattachement.
 *
 * Entrée POST : type, id, token
 * Sortie JSON : { success, message }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

/*
 * Liste blanche : le type reçu ne sert qu'à choisir une entrée de ce tableau,
 * jamais à construire du SQL. C'est ce qui rend l'endpoint générique sans
 * ouvrir d'injection sur un nom de table.
 */
const ENTITES = [
    'artiste'  => ['table' => 'artists',   'libelle' => 'Artiste',    'index' => 'artists'],
    'genre'    => ['table' => 'genres',    'libelle' => 'Genre',      'index' => null],
    'tag'      => ['table' => 'tags',      'libelle' => 'Étiquette',  'index' => null],
    'playlist' => ['table' => 'playlists', 'libelle' => 'Playlist',   'index' => null],
];

$type = (string) filter_input(INPUT_POST, 'type', FILTER_DEFAULT);
$id   = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!isset(ENTITES[$type]) || !$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$entite = ENTITES[$type];
$table  = $entite['table'];
$pdo    = Config::getConnection();

$req = $pdo->prepare("SELECT name FROM `$table` WHERE id = :id");
$req->execute([':id' => $id]);
$nom = $req->fetchColumn();

if ($nom === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $entite['libelle'] . ' introuvable']);
    exit;
}

// Les listes système sont recréées par l'application et attendues par le
// player : les supprimer casserait la file d'attente et les favoris.
if ($type === 'playlist' && in_array($nom, ['Wait Tracks', 'Favorite Tracks'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Les playlists système ne peuvent pas être supprimées',
    ]);
    exit;
}

try {
    $req = $pdo->prepare("DELETE FROM `$table` WHERE id = :id");
    $req->execute([':id' => $id]);
} catch (PDOException $e) {
    error_log("supprimer_entite ($type #$id) : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Suppression impossible en base']);
    exit;
}

// Seuls les artistes ont un index de recherche dédié.
if ($entite['index']) {
    $meili = clientMeili();
    if ($meili) {
        try {
            $meili->index(Config::indexMeili($entite['index']))->deleteDocument($id);
        } catch (\Exception $e) {
            error_log('supprimer_entite / Meilisearch : ' . $e->getMessage());
        }
    }
}

journalInfo('contenu', 'entite_supprimee',
    $entite['libelle'] . ' « ' . $nom . ' » supprimé(e)',
    ['type' => $type, 'table' => $table, 'entite_id' => $id, 'nom' => $nom]);

echo json_encode([
    'success' => true,
    'message' => $entite['libelle'] . ' « ' . $nom . ' » supprimé(e)',
]);
