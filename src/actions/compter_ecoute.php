<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

// En démonstration on acquitte sans rien écrire : les statistiques du compte
// réel ne doivent pas bouger, et le player ne doit pas voir d'erreur.
if (estDemo()) {
    echo json_encode(['success' => true, 'demo' => true]);
    exit;
}

$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
$playlistId = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);

if (!$trackId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

try {
    // Vérifie que le titre existe
    $req = $pdo->prepare("SELECT id FROM tracks WHERE id = :id");
    $req->execute([':id' => $trackId]);
    if (!$req->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Titre introuvable']);
        exit;
    }

    // Une playlist inexistante est enregistrée comme écoute hors playlist
    if ($playlistId) {
        $req = $pdo->prepare("SELECT id FROM playlists WHERE id = :id");
        $req->execute([':id' => $playlistId]);
        if (!$req->fetch()) {
            $playlistId = null;
        }
    } else {
        $playlistId = null;
    }

    // Historique d'écoute (doublon possible à la même seconde → ignoré)
    try {
        $req = $pdo->prepare("INSERT INTO historical (`listened-by_id`, track_id, playlist_id) VALUES (:user_id, :track_id, :playlist_id)");
        $req->bindValue(':user_id', $_SESSION['user']['id'], PDO::PARAM_INT);
        $req->bindValue(':track_id', $trackId, PDO::PARAM_INT);
        $req->bindValue(':playlist_id', $playlistId, $playlistId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $req->execute();
    } catch (PDOException $e) {
    }

    // Compteur d'écoutes par utilisateur
    $req = $pdo->prepare("INSERT INTO nb_listen (user_id, track_id, nb) VALUES (:user_id, :track_id, 1)
        ON DUPLICATE KEY UPDATE nb = nb + 1");
    $req->execute([':user_id' => $_SESSION['user']['id'], ':track_id' => $trackId]);

    echo json_encode(['success' => true, 'message' => 'Écoute enregistrée']);
} catch (Exception $e) {
    echecJson('compter_ecoute', $e, "Écoute non enregistrée");
}
