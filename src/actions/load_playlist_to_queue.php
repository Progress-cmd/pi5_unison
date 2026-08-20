<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$playlistId = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);

if (!$playlistId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

try {
    // Récupère l'ID de "Wait Tracks"
    $req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :user_id");
    $req->execute([':user_id' => $_SESSION['user']['id']]);
    $waitTracks = $req->fetch();

    if (!$waitTracks) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Wait Tracks introuvable']);
        exit;
    }

    $waitTracksId = $waitTracks['id'];

    // Vide "Wait Tracks"
    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :id");
    $req->execute([':id' => $waitTracksId]);

    // Récupère les chansons de la playlist
    $req = $pdo->prepare("
        SELECT track_id, position
        FROM track__playlist
        WHERE playlist_id = :id
        ORDER BY position
    ");
    $req->execute([':id' => $playlistId]);
    $tracks = $req->fetchAll(PDO::FETCH_ASSOC);

    // Ajoute les chansons à "Wait Tracks"
    $position = 1;
    foreach ($tracks as $track) {
        $req = $pdo->prepare("
            INSERT INTO track__playlist (track_id, playlist_id, position)
            VALUES (:track_id, :playlist_id, :position)
        ");
        $req->execute([
            ':track_id' => $track['track_id'],
            ':playlist_id' => $waitTracksId,
            ':position' => $position
        ]);
        $position++;
    }

    // Récupère la liste complète pour retourner au client
    $req = $pdo->prepare("
        SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
               GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
        FROM tracks
        RIGHT JOIN track__playlist ON track_id = tracks.id
        LEFT JOIN artist__track ON artist__track.track_id = tracks.id
        LEFT JOIN artists ON artists.id = artist__track.artist_id
        WHERE playlist_id = :id
        GROUP BY tracks.id, tracks.title, tracks.img, tracks.duration
        ORDER BY track__playlist.position
    ");
    $req->execute([':id' => $waitTracksId]);
    $queueTracks = $req->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'tracks' => $queueTracks]);
} catch (Exception $e) {
    echecJson('file_playlist', $e, "Impossible de charger la playlist dans la file");
}
