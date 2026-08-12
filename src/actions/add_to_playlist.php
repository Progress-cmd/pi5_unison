<?php
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$track_id    = filter_input(INPUT_POST, 'track_id',FILTER_VALIDATE_INT);
$playlist_id = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);

if (!$track_id || !$playlist_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Paramètres invalides"]);
    exit;
}

$pdo = Config::getConnection();

// Vérifie que la track n'est pas déjà dans la playlist
$req = $pdo->prepare("SELECT COUNT(*) FROM track__playlist WHERE track_id = :track AND playlist_id = :playlist");
$req->execute([':track' => $track_id, ':playlist' => $playlist_id]);

if ($req->fetchColumn() > 0) {
    echo json_encode(['success' => true, 'message' => "Déjà dans la playlist"]);
    exit;
}

// Récupère la prochaine position disponible pour cette playlist
$req = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM track__playlist WHERE playlist_id = :playlist");
$req->execute([':playlist' => $playlist_id]);
$position = $req->fetchColumn();

$req = $pdo->prepare("INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:track, :playlist, :position)");
$req->execute([':track' => $track_id, ':playlist' => $playlist_id, ':position' => $position]);

echo json_encode(['success' => true, 'message' => 'Ajouté avec succès']);
exit;