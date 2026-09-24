<?php
/**
 * Remplace la file d'attente par les titres d'un album, dans l'ordre.
 *
 * Entrée POST : album_id
 * Sortie JSON : { success, tracks }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
include_once "../includes/config.php";
include_once "../includes/albums.php";

header('Content-Type: application/json');

$albumId = filter_input(INPUT_POST, 'album_id', FILTER_VALIDATE_INT);

if (!$albumId) {
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

    $titres = albumTitres($pdo, $albumId);

    if (!$titres) {
        echo json_encode(['success' => false, 'message' => 'Album vide']);
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
    echecJson('album_lire', $e, "Impossible de charger l'album dans la file");
}
