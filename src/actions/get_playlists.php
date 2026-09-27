<?php
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";
// libellePlaylist() / playlistSysteme() : rendu.php n'est pas tiré par auth.php.
include_once "../includes/rendu.php";

header('Content-Type: application/json');

$pdo = Config::getConnection();

/*
 * track_id est facultatif. Quand il est fourni, chaque playlist indique si
 * elle contient déjà ce titre : c'est ce qui permet au sélecteur d'afficher
 * des cases à cocher plutôt qu'un « + » qui ne dit jamais où le titre se
 * trouve déjà.
 */
$titre = filter_input(INPUT_GET, 'track_id', FILTER_VALIDATE_INT) ?: null;

try {
    $req = $pdo->prepare("
        SELECT playlists.id,
               playlists.name,
               (SELECT COUNT(*) FROM track__playlist
                 WHERE track__playlist.playlist_id = playlists.id) AS nb_titres,
               " . ($titre
                    ? "(SELECT COUNT(*) FROM track__playlist
                         WHERE track__playlist.playlist_id = playlists.id
                           AND track__playlist.track_id = :track) > 0"
                    : "0") . " AS contient
        FROM playlists
        WHERE `created-by_id` = :user_id AND name != 'Wait Tracks'
        ORDER BY name
    ");
    $req->bindValue(':user_id', (int) $_SESSION['user']['id'], PDO::PARAM_INT);
    if ($titre) { $req->bindValue(':track', $titre, PDO::PARAM_INT); }
    $req->execute();

    $playlists = array_map(static function (array $p): array {
        return [
            'id'        => (int) $p['id'],
            'name'      => $p['name'],
            // Libellé affichable : « Favorite Tracks » se lit « Favoris ».
            'nom'       => libellePlaylist($p['name']),
            'nb_titres' => (int) $p['nb_titres'],
            'contient'  => (bool) $p['contient'],
            'systeme'   => playlistSysteme($p['name']),
        ];
    }, $req->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['success' => true, 'playlists' => $playlists]);
} catch (Exception $e) {
    echecJson('lister_playlists', $e, "Impossible de charger les playlists");
}
