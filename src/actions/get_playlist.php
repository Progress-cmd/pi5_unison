<?php
include_once "../includes/config.php";

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID invalide']);
    exit;
}

$pdo = Config::getConnection();

// Infos de la playlist
$req = $pdo->prepare("SELECT name, username FROM playlists LEFT JOIN users ON playlists.`created-by_id` = users.id WHERE playlists.id = :id");
$req->bindParam(':id', $id);
$req->execute();
$playlist = $req->fetch(PDO::FETCH_ASSOC);

if (!$playlist) {
    http_response_code(404);
    echo json_encode(['error' => 'Playlist introuvable']);
    exit;
}

// Tracks de la playlist
$req = $pdo->prepare("
    SELECT tracks.id, title, duration, img,
           GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
    FROM tracks
    RIGHT JOIN track__playlist ON track_id = tracks.id
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists ON artists.id = artist__track.artist_id
    WHERE playlist_id = :id
    GROUP BY tracks.id, title, duration, img
");
$req->bindParam(':id', $id, PDO::PARAM_INT);
$req->execute();
$playlist['tracks'] = $req->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($playlist);