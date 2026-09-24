<?php
/**
 * Supprime un album.
 *
 * Les titres que CET album a fait entrer dans la discothèque partent avec lui,
 * base et fichier. Tous les autres sont détachés et conservés — voir
 * albumSupprimer() pour le détail de cette distinction, qui est le cœur de la
 * fonctionnalité.
 *
 * Entrée POST : album_id
 * Sortie JSON : { success, message, supprimes, detaches }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";
include_once "../includes/albums.php";

header('Content-Type: application/json');

$albumId = filter_input(INPUT_POST, 'album_id', FILTER_VALIDATE_INT);

if (!$albumId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$pdo = Config::getConnection();

try {
    $res = albumSupprimer($pdo, $albumId, true);

    if ($res['success']) {
        $res['message'] = $res['supprimes'] > 0
            ? sprintf('Album supprimé — %d titre(s) retiré(s), %d conservé(s)',
                      $res['supprimes'], $res['detaches'])
            : sprintf('Album supprimé — %d titre(s) conservé(s)', $res['detaches']);
    }

    echo json_encode($res);
} catch (Throwable $e) {
    echecJson('album_supprimer', $e, "Suppression impossible");
}
