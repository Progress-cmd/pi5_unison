<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$playlistId = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);
$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);

if (!$playlistId || !$trackId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

/*
 * Retrait : le propriétaire de la playlist, ou celui qui a déposé CE titre.
 *
 * Le second cas est nécessaire à la cohérence du sélecteur : depuis qu'on peut
 * cocher une playlist de l'autre compte, il faut pouvoir décocher. Sans ça la
 * case se rebellait — l'ajout passait, le retrait rendait 403, et la coche
 * revenait toute seule.
 *
 * Ça ne donne aucun droit sur les titres d'autrui : seule la ligne qu'on a
 * soi-même ajoutée est concernée.
 */
$cible = exigerPlaylistOuverteALAjout($pdo, $playlistId);

if (!$cible['mienne']) {
    $req = $pdo->prepare("
        SELECT `added-by_id` FROM track__playlist
         WHERE playlist_id = :playlist AND track_id = :track
    ");
    $req->execute([':playlist' => $playlistId, ':track' => $trackId]);
    $deposePar = $req->fetchColumn();

    if ((int) $deposePar !== (int) ($_SESSION['user']['id'] ?? 0)) {
        journalAttention('contenu', 'retrait_playlist_etrangere',
            "Tentative de retrait d'un titre qu'on n'a pas déposé",
            ['playlist' => $playlistId, 'titre' => $trackId,
             'proprietaire' => $cible['proprietaire']]);

        http_response_code(403);
        echo json_encode(['success' => false,
            'message' => "Ce titre a été ajouté par quelqu'un d'autre"]);
        exit;
    }
}

try {
    // Supprime la chanson de la playlist
    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :playlist_id AND track_id = :track_id");
    $req->execute([':playlist_id' => $playlistId, ':track_id' => $trackId]);

    // Réorganise les positions
    $req = $pdo->prepare("
        SELECT track_id, position FROM track__playlist
        WHERE playlist_id = :playlist_id
        ORDER BY position
    ");
    $req->execute([':playlist_id' => $playlistId]);
    $tracks = $req->fetchAll(PDO::FETCH_ASSOC);

    $updateReq = $pdo->prepare("UPDATE track__playlist SET position = :position WHERE playlist_id = :playlist_id AND track_id = :track_id");
    foreach ($tracks as $index => $track) {
        $updateReq->execute([
            ':position' => $index,
            ':playlist_id' => $playlistId,
            ':track_id' => $track['track_id']
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Chanson supprimée']);
} catch (Exception $e) {
    echecJson('retirer_titre', $e, "Impossible de retirer ce titre");
}
