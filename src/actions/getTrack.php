<?php
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";
$pdo = Config::getConnection();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$req = $pdo->prepare("
    SELECT tracks.id, tracks.file, tracks.title, tracks.img, tracks.duration, GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
    FROM tracks
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists ON artists.id = artist__track.artist_id
    WHERE tracks.id = :id
    LIMIT 1
");
$req->bindParam(':id', $id);
$req->execute();

$track = $req->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    echo json_encode(null);
    exit;
}

echo json_encode([
    "id" => $track["id"],
    "src" => "actions/stream.php?file=" . urlencode($track["file"]),
    "title" => $track["title"],
    "artist" => $track["artists_names"],
    "img" => $track["img"],
    "duration" => $track["duration"]
]);
