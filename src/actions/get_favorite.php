<?php
include_once "../includes/config.php";
header('Content-Type: application/json');

session_start();
$user_id  = $_SESSION['user']['id'] ?? null;
$track_id = filter_input(INPUT_GET, 'track_id', FILTER_VALIDATE_INT);

if (!$track_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$pdo = Config::getConnection();

// Récupère la playlist "favoris" de l'utilisateur
$req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Favorite Tracks' AND `created-by_id` = :user_id");
$req->execute([':user_id' => $user_id]);
$playlist = $req->fetch(PDO::FETCH_ASSOC);

if (!$playlist) {
    echo json_encode(['success' => false, 'message' => 'Playlist favoris introuvable']);
    exit;
}

$playlist_id = $playlist['id'];

// Vérifie si déjà en favori
$req = $pdo->prepare("SELECT COUNT(*) FROM track__playlist WHERE track_id = :track AND playlist_id = :playlist");
$req->bindParam(':track',    $track_id,    PDO::PARAM_INT);
$req->bindParam(':playlist', $playlist_id, PDO::PARAM_INT);
$req->execute();

if ($req->fetchColumn() > 0) {
    echo json_encode(['status' => true, 'liked' => true]);
} else {
    echo json_encode(['status' => true, 'liked' => false]);
}
