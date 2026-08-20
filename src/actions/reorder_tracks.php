<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$playlist_id = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);
$track_id = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
$new_position = filter_input(INPUT_POST, 'position', FILTER_VALIDATE_INT);

if (!$playlist_id || !$track_id || $new_position === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$pdo = Config::getConnection();

exigerPlaylistDeLUtilisateur($pdo, $playlist_id);

try {
    $pdo->beginTransaction();

    // Récupère la position actuelle de la piste
    $req = $pdo->prepare("SELECT position FROM track__playlist WHERE track_id = :track AND playlist_id = :playlist");
    $req->execute([':track' => $track_id, ':playlist' => $playlist_id]);
    $current = $req->fetch();

    if (!$current) {
        throw new Exception("Piste non trouvée dans la playlist");
    }

    $old_pos = $current['position'];

    // Décale les autres pistes selon la direction du déplacement
    if ($new_position > $old_pos) {
        // Déplacement vers le bas
        $req = $pdo->prepare("
            UPDATE track__playlist
            SET position = position - 1
            WHERE playlist_id = :playlist
            AND position > :old_pos
            AND position <= :new_pos
        ");
        $req->execute([
            ':playlist' => $playlist_id,
            ':old_pos' => $old_pos,
            ':new_pos' => $new_position
        ]);
    } else if ($new_position < $old_pos) {
        // Déplacement vers le haut
        $req = $pdo->prepare("
            UPDATE track__playlist
            SET position = position + 1
            WHERE playlist_id = :playlist
            AND position >= :new_pos
            AND position < :old_pos
        ");
        $req->execute([
            ':playlist' => $playlist_id,
            ':new_pos' => $new_position,
            ':old_pos' => $old_pos
        ]);
    }

    // Mets à jour la position de la piste
    $req = $pdo->prepare("UPDATE track__playlist SET position = :new_pos WHERE track_id = :track AND playlist_id = :playlist");
    $req->execute([
        ':new_pos' => $new_position,
        ':track' => $track_id,
        ':playlist' => $playlist_id
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Piste réordonnée']);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echecJson('reordonner', $e, "Impossible de réordonner la playlist");
}