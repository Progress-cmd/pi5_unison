<?php
/**
 * Renvoie la liste d'attente (« Wait Tracks ») de l'utilisateur courant.
 *
 * La page d'accueil injecte déjà cette liste dans la page. Cet endpoint sert
 * au player quand l'application est ouverte ailleurs qu'à l'accueil : sans
 * lui, la file restait vide et aucun titre n'était lançable depuis le player.
 *
 * Sortie JSON : { success, tracks: [{id, title, img, duration, artists_names}] }
 */
include_once "../includes/auth.php";
include_once "../includes/config.php";

exigerConnexion(true);
header('Content-Type: application/json');

$userId = (int) $_SESSION['user']['id'];

// La lecture est terminée côté session : on libère le verrou tout de suite,
// le player interroge cet endpoint au chargement de l'application.
session_write_close();

$pdo = Config::getConnection();

$req = $pdo->prepare("
    SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
           playlists.id AS playlist_id,
           GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
    FROM playlists
    JOIN track__playlist ON track__playlist.playlist_id = playlists.id
    JOIN tracks          ON tracks.id = track__playlist.track_id
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists       ON artists.id = artist__track.artist_id
    WHERE playlists.name = 'Wait Tracks' AND playlists.`created-by_id` = :user
    GROUP BY tracks.id, tracks.title, tracks.img, tracks.duration, playlists.id, track__playlist.position
    ORDER BY track__playlist.position
");
$req->execute([':user' => $userId]);

echo json_encode(['success' => true, 'tracks' => $req->fetchAll(PDO::FETCH_ASSOC)]);
