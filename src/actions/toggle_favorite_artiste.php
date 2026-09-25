<?php
/**
 * Bascule un artiste en favori, ou l'en retire.
 *
 * Contrairement aux titres favoris, qui vivent dans une playlist système,
 * les artistes favoris ont leur propre table de liaison : une playlist
 * contient des titres, pas des artistes.
 *
 * Entrée POST : artist_id
 * Sortie JSON : { success, favori }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$artistId = filter_input(INPUT_POST, 'artist_id', FILTER_VALIDATE_INT);

if (!$artistId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$userId = (int) $_SESSION['user']['id'];
$pdo = Config::getConnection();

try {
    $req = $pdo->prepare("SELECT id FROM artists WHERE id = :id");
    $req->execute([':id' => $artistId]);
    if (!$req->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Artiste introuvable']);
        exit;
    }

    $req = $pdo->prepare(
        "SELECT 1 FROM artist__favorite WHERE user_id = :user AND artist_id = :artiste"
    );
    $req->execute([':user' => $userId, ':artiste' => $artistId]);
    $estFavori = (bool) $req->fetchColumn();

    if ($estFavori) {
        $req = $pdo->prepare(
            "DELETE FROM artist__favorite WHERE user_id = :user AND artist_id = :artiste"
        );
    } else {
        $req = $pdo->prepare(
            "INSERT INTO artist__favorite (user_id, artist_id) VALUES (:user, :artiste)"
        );
    }
    $req->execute([':user' => $userId, ':artiste' => $artistId]);

    echo json_encode(['success' => true, 'favori' => !$estFavori]);
} catch (Throwable $e) {
    echecJson('favori_artiste', $e, 'Impossible de modifier les favoris');
}
