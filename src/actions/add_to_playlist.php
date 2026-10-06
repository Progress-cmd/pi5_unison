<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
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

/*
 * Les playlists ordinaires du foyer sont communes : chacun peut déposer un
 * titre chez l'autre. Restent fermées les playlists système d'autrui — file
 * d'attente et favoris — qui ne sont pas des listes partagées mais l'état
 * personnel d'un compte.
 *
 * Tout le reste (retirer, renommer, supprimer, réordonner) continue de passer
 * par exigerPlaylistDeLUtilisateur().
 */
$cible = exigerPlaylistOuverteALAjout($pdo, $playlist_id);

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

/*
 * On note QUI a ajouté. Sans ça, rien ne distinguerait dans la playlist de
 * quelqu'un un titre qu'il a choisi d'un titre déposé par l'autre compte —
 * et c'est précisément ce qu'il faut pouvoir montrer.
 */
$req = $pdo->prepare("
    INSERT INTO track__playlist (track_id, playlist_id, position, `added-by_id`)
    VALUES (:track, :playlist, :position, :auteur)
");
$req->execute([
    ':track'    => $track_id,
    ':playlist' => $playlist_id,
    ':position' => $position,
    ':auteur'   => (int) $_SESSION['user']['id'],
]);

if (!$cible['mienne']) {
    journalInfo('contenu', 'ajout_playlist_partagee',
        "Titre ajouté dans la playlist d'un autre compte",
        ['playlist' => $playlist_id, 'titre' => $track_id,
         'proprietaire' => $cible['proprietaire']]);
}

echo json_encode(['success' => true, 'message' => 'Ajouté avec succès']);
exit;