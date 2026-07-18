<?php
session_start();
include_once "../includes/config.php";

$playlistId = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);
$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);

if (!$playlistId || !$trackId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

try {
    // Supprime la chanson de la playlist
    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :playlist_id AND track_id = :track_id");
    $req->execute([':playlist_id' => $playlistId, ':track_id' => $trackId]);

    // Réorganise les positions
    $req = $pdo->prepare("
        SELECT track_id, position FROM track__playlist
        WHERE playlist_id = :playlist_id
        ORDER BY position
    ");
    $req->execute([':playlist_id' => $playlistId]);
    $tracks = $req->fetchAll(PDO::FETCH_ASSOC);

    $updateReq = $pdo->prepare("UPDATE track__playlist SET position = :position WHERE playlist_id = :playlist_id AND track_id = :track_id");
    foreach ($tracks as $index => $track) {
        $updateReq->execute([
            ':position' => $index,
            ':playlist_id' => $playlistId,
            ':track_id' => $track['track_id']
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Chanson supprimée']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
