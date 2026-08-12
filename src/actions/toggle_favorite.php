<?php
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
include_once "../includes/config.php";
header('Content-Type: application/json');

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
    // Déjà en favori → on retire
    $req = $pdo->prepare("DELETE FROM track__playlist WHERE track_id = :track AND playlist_id = :playlist");
    $req->execute([':track' => $track_id, ':playlist' => $playlist_id]);
    echo json_encode(['success' => true, 'liked' => false, 'message' => 'Retiré des favoris']);
    exit;
} else {
    // Pas en favori → on ajoute
    $req = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM track__playlist WHERE playlist_id = :playlist");
    $req->execute([':playlist' => $playlist_id]);
    $position = $req->fetchColumn();

    $req = $pdo->prepare("INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:track, :playlist, :position)");
    $req->execute([':track' => $track_id, ':playlist' => $playlist_id, ':position' => $position]);
    echo json_encode(['success' => true, 'liked' => true, 'message' => 'Ajouté aux favoris']);
    exit;
}