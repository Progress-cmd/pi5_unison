<?php
header('Content-Type: application/json');
session_start();
include_once "../includes/config.php";

$playlistId = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);
$text = isset($_POST['text']) ? trim($_POST['text']) : '';

if (!$playlistId || !$text) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

// Vérifie que la playlist existe
$req = $pdo->prepare("SELECT id FROM playlists WHERE id = :id");
$req->execute([':id' => $playlistId]);
if (!$req->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Playlist introuvable']);
    exit;
}

try {
    // Crée la note
    $req = $pdo->prepare("INSERT INTO notes (text, `created-by_id`) VALUES (:text, :user_id)");
    $req->execute([':text' => $text, ':user_id' => $_SESSION['user']['id']]);
    $noteId = $pdo->lastInsertId();

    // Associe la note à la playlist
    $req = $pdo->prepare("INSERT INTO note__playlist (note_id, playlist_id) VALUES (:note_id, :playlist_id)");
    $req->execute([':note_id' => $noteId, ':playlist_id' => $playlistId]);

    echo json_encode(['success' => true, 'message' => 'Note ajoutée']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
