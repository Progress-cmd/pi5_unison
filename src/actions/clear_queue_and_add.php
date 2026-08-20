<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
// Cet endpoint vide une playlist (DELETE) : il doit refuser la démonstration
// au même titre que les autres écritures.
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$track_id = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);

if (!$track_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$pdo = Config::getConnection();

/*
 * La file visée n'est plus celle que le client désigne.
 *
 * L'identifiant arrivait en POST, et la première chose faite avec était un
 * DELETE sans condition : envoyer le numéro de la playlist d'un autre compte
 * suffisait à la vider. Elle est désormais résolue ici, à partir de la seule
 * session — ce qui règle du même coup le cas où la page ne connaissait pas
 * encore l'identifiant et postait « null », laissant le clic sans effet.
 */
$req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :user");
$req->execute([':user' => (int) $_SESSION['user']['id']]);
$playlist_id = (int) $req->fetchColumn();

if (!$playlist_id) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "Liste d'attente introuvable"]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Vide la playlist "Wait Tracks"
    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :playlist");
    $req->execute([':playlist' => $playlist_id]);
    
    // Ajoute la nouvelle musique en position 1
    $req = $pdo->prepare("INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:track, :playlist, 1)");
    $req->execute([':track' => $track_id, ':playlist' => $playlist_id]);
    
    // Complète la file avec des titres tirés au hasard
    $req = $pdo->prepare("
        SELECT id FROM tracks 
        WHERE id != :track_id
        ORDER BY RAND() 
        LIMIT 49
    ");
    $req->execute([':track_id' => $track_id]);
    $randomTracks = $req->fetchAll(PDO::FETCH_ASSOC);
    
    $position = 2;
    foreach ($randomTracks as $rt) {
        $req = $pdo->prepare("INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:track, :playlist, :pos)");
        $req->execute([':track' => $rt['id'], ':playlist' => $playlist_id, ':pos' => $position]);
        $position++;
    }
    
    $pdo->commit();
    
    // Récupère la nouvelle liste d'attente
    $req = $pdo->prepare("
        SELECT tracks.id, tracks.img, tracks.title, GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
        FROM track__playlist
        LEFT JOIN tracks ON track_id = tracks.id
        LEFT JOIN artist__track ON artist__track.track_id = tracks.id
        LEFT JOIN artists ON artists.id = artist__track.artist_id
        WHERE playlist_id = :playlist
        GROUP BY tracks.id
        ORDER BY position
    ");
    $req->execute([':playlist' => $playlist_id]);
    $newQueue = $req->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'queue' => $newQueue, 'playlist_id' => $playlist_id]);
    
} catch (Exception $e) {
    // rollBack() hors transaction lève à son tour, et masque l'erreur d'origine.
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echecJson('file_remplacer', $e, "Impossible de mettre à jour la file d'attente");
}
