<?php
header('Content-Type: application/json');
session_start();
include_once "../includes/config.php";

$playlistId = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$tags = isset($_POST['tags']) ? array_map('intval', $_POST['tags']) : [];

if (!$playlistId || !$name) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

// Vérifie que la playlist existe et n'est pas système
$req = $pdo->prepare("SELECT name FROM playlists WHERE id = :id");
$req->execute([':id' => $playlistId]);
$playlist = $req->fetch();

if (!$playlist) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Playlist introuvable']);
    exit;
}

if (in_array($playlist['name'], ['Wait Tracks', 'Favorite Tracks'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Impossible de modifier les playlists système']);
    exit;
}

try {
    // Met à jour le nom
    $req = $pdo->prepare("UPDATE playlists SET name = :name WHERE id = :id");
    $req->execute([':name' => $name, ':id' => $playlistId]);

    // Supprime les tags existants
    $req = $pdo->prepare("DELETE FROM tag__playlist WHERE playlist_id = :playlist_id");
    $req->execute([':playlist_id' => $playlistId]);

    // Ajoute les nouveaux tags
    if (!empty($tags)) {
        $req = $pdo->prepare("INSERT INTO tag__playlist (tag_id, playlist_id) VALUES (:tag_id, :playlist_id)");
        foreach ($tags as $tagId) {
            $req->execute([':tag_id' => $tagId, ':playlist_id' => $playlistId]);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Playlist mise à jour',
        'redirect' => 'library/playlists'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
