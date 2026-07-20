<?php
session_start();
include_once "../includes/config.php";

header('Content-Type: application/json');

$track_id = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
$playlist_id = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);

if (!$track_id || !$playlist_id) {
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$pdo = Config::getConnection();

try {
    $pdo->beginTransaction();
    
    // Vide la playlist "Wait Tracks"
    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :playlist");
    $req->execute([':playlist' => $playlist_id]);
    
    // Ajoute la nouvelle musique en position 1
    $req = $pdo->prepare("INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:track, :playlist, 1)");
    $req->execute([':track' => $track_id, ':playlist' => $playlist_id]);
    
    // Ajoute 7 musiques aléatoires après
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
    
    echo json_encode(['success' => true, 'queue' => $newQueue]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
