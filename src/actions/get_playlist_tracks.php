<?php
header('Content-Type: application/json');
session_start();
include_once "../includes/config.php";

$playlistId = filter_input(INPUT_GET, 'playlist_id', FILTER_VALIDATE_INT);

if (!$playlistId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID playlist invalide']);
    exit;
}

$pdo = Config::getConnection();

try {
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
    $req->execute([':id' => $playlistId]);
    $tracks = $req->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'tracks' => $tracks]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
