<?php
/**
 * Remplace la file d'attente par tous les titres d'un artiste.
 *
 * Entrée POST : artist_id
 * Sortie JSON : { success, tracks }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$artistId = filter_input(INPUT_POST, 'artist_id', FILTER_VALIDATE_INT);

if (!$artistId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$userId = (int) $_SESSION['user']['id'];
session_write_close();

$pdo = Config::getConnection();

try {
    $req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :user");
    $req->execute([':user' => $userId]);
    $fileId = (int) $req->fetchColumn();

    if (!$fileId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Liste d'attente introuvable"]);
        exit;
    }

    /*
     * Tous les titres où l'artiste figure, y compris les collaborations :
     * la jointure at1 filtre sur lui, at2 rassemble ensuite l'ensemble des
     * artistes de chaque titre pour l'affichage.
     */
    $req = $pdo->prepare("
        SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
               GROUP_CONCAT(DISTINCT a2.name ORDER BY a2.name SEPARATOR ', ') AS artists_names
          FROM tracks
          JOIN artist__track at1 ON at1.track_id = tracks.id AND at1.artist_id = :artiste
          LEFT JOIN artist__track at2 ON at2.track_id = tracks.id
          LEFT JOIN artists a2 ON a2.id = at2.artist_id
         GROUP BY tracks.id, tracks.title, tracks.img, tracks.duration
         ORDER BY tracks.title
    ");
    $req->execute([':artiste' => $artistId]);
    $titres = $req->fetchAll(PDO::FETCH_ASSOC);

    if (!$titres) {
        echo json_encode(['success' => false, 'message' => 'Aucun titre pour cet artiste']);
        exit;
    }

    $pdo->beginTransaction();

    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :file");
    $req->execute([':file' => $fileId]);

    $req = $pdo->prepare(
        "INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:track, :file, :pos)"
    );
    foreach ($titres as $i => $t) {
        $req->execute([':track' => (int) $t['id'], ':file' => $fileId, ':pos' => $i + 1]);
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'tracks' => $titres]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echecJson('artiste_lire', $e, "Impossible de charger les titres de l'artiste");
}
