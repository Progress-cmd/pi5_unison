<?php
header('Content-Type: application/json');
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
include_once "../includes/config.php";

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
$genres = isset($_POST['genres']) ? array_map('intval', $_POST['genres']) : [];
$tags = isset($_POST['tags']) ? array_map('intval', $_POST['tags']) : [];

if (!$trackId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

// Vérifie que le titre existe
$req = $pdo->prepare("SELECT id FROM tracks WHERE id = :id");
$req->execute([':id' => $trackId]);
if (!$req->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Titre introuvable']);
    exit;
}

try {
    // Remplace les genres du titre
    $req = $pdo->prepare("DELETE FROM track__genre WHERE track_id = :track_id");
    $req->execute([':track_id' => $trackId]);

    if (!empty($genres)) {
        $req = $pdo->prepare("INSERT INTO track__genre (track_id, genre_id) VALUES (:track_id, :genre_id)");
        foreach ($genres as $genreId) {
            $req->execute([':track_id' => $trackId, ':genre_id' => $genreId]);
        }
    }

    // Remplace les tags du titre
    $req = $pdo->prepare("DELETE FROM tag__track WHERE track_id = :track_id");
    $req->execute([':track_id' => $trackId]);

    if (!empty($tags)) {
        $req = $pdo->prepare("INSERT INTO tag__track (tag_id, track_id) VALUES (:tag_id, :track_id)");
        foreach ($tags as $tagId) {
            $req->execute([':tag_id' => $tagId, ':track_id' => $trackId]);
        }
    }

    // Propagation additive vers les artistes du titre : on n'enlève jamais
    // un genre d'artiste, il peut venir d'autres titres
    if (!empty($genres)) {
        $req = $pdo->prepare("SELECT artist_id FROM artist__track WHERE track_id = :track_id");
        $req->execute([':track_id' => $trackId]);
        $artistIds = $req->fetchAll(PDO::FETCH_COLUMN);

        $reqCheck = $pdo->prepare("SELECT COUNT(*) FROM artist__genre WHERE artist_id = :artist_id AND genre_id = :genre_id");
        $reqInsert = $pdo->prepare("INSERT INTO artist__genre (artist_id, genre_id) VALUES (:artist_id, :genre_id)");
        foreach ($artistIds as $artistId) {
            foreach ($genres as $genreId) {
                $reqCheck->execute([':artist_id' => $artistId, ':genre_id' => $genreId]);
                if ($reqCheck->fetchColumn() == 0) {
                    $reqInsert->execute([':artist_id' => $artistId, ':genre_id' => $genreId]);
                }
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Titre mis à jour']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
