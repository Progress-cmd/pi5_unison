<?php
/**
 * Remplit la liste d'attente (« Wait Tracks ») avec l'intégralité des titres
 * de l'application, dans un ordre aléatoire.
 *
 * L'ancienne file est remplacée : le bouton « Tout écouter » de la
 * bibliothèque est une action de lecture, pas un ajout.
 *
 * Sortie JSON : { success, tracks: [{id, title, img, duration, artists_names}] }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$userId = (int) $_SESSION['user']['id'];

// Plus rien ne dépend de la session ici : on libère le verrou, le remplissage
// de la file peut être long et bloquerait les autres requêtes de la page.
session_write_close();

$pdo = Config::getConnection();

try {
    $req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :user");
    $req->execute([':user' => $userId]);
    $fileId = $req->fetchColumn();

    if (!$fileId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Liste d'attente introuvable"]);
        exit;
    }

    $fileId = (int) $fileId;

    $req = $pdo->query("SELECT id FROM tracks");
    $identifiants = $req->fetchAll(PDO::FETCH_COLUMN);
    $nombre = count($identifiants);

    /*
     * Mélange côté PHP plutôt qu'un ORDER BY RAND() : l'ordre d'une sous-requête
     * n'est pas garanti d'être conservé par l'INSERT, et la position — donc
     * l'ordre de lecture — en dépend entièrement.
     */
    shuffle($identifiants);

    $pdo->beginTransaction();

    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :file");
    $req->execute([':file' => $fileId]);

    /*
     * Insertion par paquets : la file couvre ici toute la discothèque, et un
     * aller-retour par titre rendait l'opération inutilisable dès quelques
     * centaines de pistes.
     */
    $position = 1;
    foreach (array_chunk($identifiants, 500) as $paquet) {
        $valeurs = [];
        $parametres = [];
        foreach ($paquet as $trackId) {
            $valeurs[] = '(?, ?, ?)';
            array_push($parametres, (int) $trackId, $fileId, $position);
            $position++;
        }
        $req = $pdo->prepare(
            "INSERT INTO track__playlist (track_id, playlist_id, position) VALUES " . implode(', ', $valeurs)
        );
        $req->execute($parametres);
    }

    $pdo->commit();

    if ($nombre === 0) {
        echo json_encode(['success' => true, 'tracks' => []]);
        exit;
    }

    $req = $pdo->prepare("
        SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
        FROM track__playlist
        JOIN tracks             ON tracks.id = track__playlist.track_id
        LEFT JOIN artist__track ON artist__track.track_id = tracks.id
        LEFT JOIN artists       ON artists.id = artist__track.artist_id
        WHERE track__playlist.playlist_id = :file
        GROUP BY tracks.id, tracks.title, tracks.img, tracks.duration, track__playlist.position
        ORDER BY track__playlist.position
    ");
    $req->execute([':file' => $fileId]);

    journalInfo('contenu', 'file_tout_aleatoire', "File d'attente remplie avec toute la discothèque", ['titres' => $nombre]);

    echo json_encode(['success' => true, 'tracks' => $req->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    journalErreur('contenu', 'file_tout_aleatoire', $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => "Impossible de remplir la liste d'attente"]);
}
