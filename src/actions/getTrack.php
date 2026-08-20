<?php
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";
$pdo = Config::getConnection();

header('Content-Type: application/json');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    echo json_encode(null);
    exit;
}

/*
 * Le GROUP BY n'est pas décoratif.
 *
 * Sans lui, la requête porte un agrégat (GROUP_CONCAT) sans regroupement :
 * MariaDB considère alors tout le résultat comme un seul groupe et renvoie
 * TOUJOURS une ligne — même quand aucun titre ne correspond, auquel cas
 * toutes les colonnes valent NULL. Le « if (!$track) » plus bas ne se
 * déclenchait donc jamais, et l'endpoint répondait un faux titre d'id null
 * que le lecteur essayait de jouer, au lieu de répondre « rien ».
 */
$req = $pdo->prepare("
    SELECT tracks.id, tracks.file, tracks.title, tracks.img, tracks.duration,
           GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
    FROM tracks
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists ON artists.id = artist__track.artist_id
    WHERE tracks.id = :id
    GROUP BY tracks.id, tracks.file, tracks.title, tracks.img, tracks.duration
    LIMIT 1
");
$req->bindValue(':id', $id, PDO::PARAM_INT);
$req->execute();

$track = $req->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    http_response_code(404);
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
