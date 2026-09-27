<?php
/**
 * Ajoute un titre à la liste d'attente, sans la remplacer.
 *
 * Le seul geste qui existait jusqu'ici était clear_queue_and_add.php : il vide
 * la file et la reconstruit autour du titre choisi. C'est le bon geste pour
 * « écouter ça maintenant », mais il n'y en avait aucun pour ajouter un titre
 * à une file qu'on est en train d'écouter — il fallait tout perdre.
 *
 * Deux modes :
 *   - « fin »      : le titre part en queue de file.
 *   - « suivant »  : il s'intercale juste après le titre en cours, ce qui
 *                    demande de décaler les positions suivantes.
 *
 * Sortie JSON : { success, message, queue: [...], playlist_id }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
$mode    = $_POST['mode'] ?? 'fin';
// Titre après lequel insérer. Absent (rien en lecture) : on se place en tête.
$apres   = filter_input(INPUT_POST, 'apres_track_id', FILTER_VALIDATE_INT) ?: null;

if (!$trackId || !in_array($mode, ['fin', 'suivant'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$pdo = Config::getConnection();

/*
 * La file est résolue depuis la session, jamais depuis le client : c'est la
 * règle posée dans clear_queue_and_add.php après qu'un playlist_id envoyé en
 * POST ait permis d'écrire dans la file d'un autre compte.
 */
$req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :user");
$req->execute([':user' => (int) $_SESSION['user']['id']]);
$fileId = (int) $req->fetchColumn();

if (!$fileId) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "Liste d'attente introuvable"]);
    exit;
}

// Le titre doit exister : un identifiant fantaisiste laisserait une ligne
// orpheline dans la file, affichée comme « titre introuvable » par le player.
$req = $pdo->prepare("SELECT title FROM tracks WHERE id = :id");
$req->execute([':id' => $trackId]);
$titre = $req->fetchColumn();

if ($titre === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Titre introuvable']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Déjà présent : on le retire d'abord, sinon « écouter juste après » sur un
    // titre déjà en file le dupliquerait au lieu de le déplacer.
    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :file AND track_id = :track");
    $req->execute([':file' => $fileId, ':track' => $trackId]);

    if ($mode === 'fin') {
        $req = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM track__playlist WHERE playlist_id = :file");
        $req->execute([':file' => $fileId]);
        $position = (int) $req->fetchColumn();

    } else {
        $position = 1;

        if ($apres !== null) {
            $req = $pdo->prepare("SELECT position FROM track__playlist WHERE playlist_id = :file AND track_id = :track");
            $req->execute([':file' => $fileId, ':track' => $apres]);
            $courante = $req->fetchColumn();
            if ($courante !== false) { $position = (int) $courante + 1; }
        }

        // Place nette : tout ce qui suit recule d'un rang.
        $req = $pdo->prepare("
            UPDATE track__playlist SET position = position + 1
            WHERE playlist_id = :file AND position >= :position
        ");
        $req->execute([':file' => $fileId, ':position' => $position]);
    }

    $req = $pdo->prepare("INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:track, :file, :position)");
    $req->execute([':track' => $trackId, ':file' => $fileId, ':position' => $position]);

    $pdo->commit();

    // La file complète revient avec la réponse : le player met à jour
    // window.waitPlaylist et la carte « Liste d'attente » sans recharger.
    $req = $pdo->prepare("
        SELECT tracks.id, tracks.img, tracks.title, tracks.duration,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
        FROM track__playlist
        LEFT JOIN tracks ON track__playlist.track_id = tracks.id
        LEFT JOIN artist__track ON artist__track.track_id = tracks.id
        LEFT JOIN artists ON artists.id = artist__track.artist_id
        WHERE track__playlist.playlist_id = :file
        GROUP BY tracks.id
        ORDER BY track__playlist.position
    ");
    $req->execute([':file' => $fileId]);
    $file = $req->fetchAll(PDO::FETCH_ASSOC);

    journalInfo('contenu', 'file_ajouter', "Titre ajouté à la liste d'attente", [
        'track_id' => $trackId,
        'mode'     => $mode,
        'position' => $position,
    ]);

    echo json_encode([
        'success'     => true,
        'message'     => $mode === 'fin' ? "Ajouté à la liste d'attente" : 'Sera lu juste après',
        'queue'       => $file,
        'playlist_id' => $fileId,
    ]);

} catch (Exception $e) {
    // rollBack() hors transaction lève à son tour et masque l'erreur d'origine.
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echecJson('file_ajouter', $e, "Impossible de mettre à jour la liste d'attente");
}
