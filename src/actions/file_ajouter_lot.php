<?php
/**
 * Envoie dans la liste d'attente tous les titres portant les étiquettes
 * demandées.
 *
 * Le pendant en lot de file_ajouter.php. La sélection est refaite ici, à
 * partir des étiquettes, et non reçue sous forme de liste d'identifiants :
 * le client n'a pas à être cru sur la composition du lot, et une liste de
 * plusieurs centaines d'identifiants n'a rien à faire dans une requête.
 *
 * Entrée POST : tags (ids séparés par des virgules), mode (et|ou),
 *               position (fin|suivant), remplacer (0|1)
 * Sortie JSON : { success, message, ajoutes, ignores, queue, playlist_id }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$entiers = static function (?string $brut): array {
    return array_values(array_filter(array_map(
        static fn ($v) => (int) $v,
        explode(',', (string) $brut)
    ), static fn (int $v): bool => $v > 0));
};

$tagsDemandes   = $entiers($_POST['tags'] ?? '');
$genresDemandes = $entiers($_POST['genres'] ?? '');

// Mêmes conditions que actions/etiquettes.php : la sélection envoyée dans la
// file doit être exactement celle qui était affichée.
$conditions = static function (array $ids, string $table, string $colonne, string $mode): array {
    if (!$ids) { return []; }
    $exists = static fn (string $in): string =>
        "EXISTS (SELECT 1 FROM $table WHERE $table.track_id = tracks.id AND $table.$colonne IN ($in))";
    if ($mode === 'ou') { return [$exists(implode(',', $ids))]; }
    return array_map(static fn (int $id): string => $exists((string) $id), $ids);
};

$mode      = ($_POST['mode'] ?? 'et') === 'ou' ? 'ou' : 'et';
$position  = ($_POST['position'] ?? 'fin') === 'suivant' ? 'suivant' : 'fin';
$remplacer = !empty($_POST['remplacer']);
$apres     = filter_input(INPUT_POST, 'apres_track_id', FILTER_VALIDATE_INT) ?: null;

if (!$tagsDemandes && !$genresDemandes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Aucune étiquette ni genre sélectionné']);
    exit;
}

$pdo = Config::getConnection();

// La file est résolue depuis la session, jamais depuis le client : même règle
// que clear_queue_and_add.php et file_ajouter.php.
$req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :u");
$req->execute([':u' => (int) $_SESSION['user']['id']]);
$fileId = (int) $req->fetchColumn();

if (!$fileId) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "Liste d'attente introuvable"]);
    exit;
}

$clauses = array_merge(
    $conditions($tagsDemandes, 'tag__track', 'tag_id', $mode),
    $conditions($genresDemandes, 'track__genre', 'genre_id', $mode)
);
$where = implode($mode === 'ou' ? ' OR ' : ' AND ', $clauses);

try {
    $selection = $pdo->query("
        SELECT tracks.id
          FROM tracks
         WHERE $where
         ORDER BY tracks.title ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!$selection) {
        echo json_encode(['success' => false, 'message' => 'Aucun titre ne correspond à cette sélection']);
        exit;
    }

    $pdo->beginTransaction();

    if ($remplacer) {
        $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :file");
        $req->execute([':file' => $fileId]);
    }

    /*
     * Les titres déjà présents sont retirés avant réinsertion : sans ça, un
     * lot rejoué dupliquerait la moitié de la file. C'est la même précaution
     * que dans file_ajouter.php, appliquée à l'ensemble.
     */
    $dejaLa = 0;
    if (!$remplacer) {
        $req = $pdo->prepare("
            SELECT COUNT(*) FROM track__playlist
             WHERE playlist_id = :file AND track_id IN (" . implode(',', array_map('intval', $selection)) . ")
        ");
        $req->execute([':file' => $fileId]);
        $dejaLa = (int) $req->fetchColumn();

        $req = $pdo->prepare("
            DELETE FROM track__playlist
             WHERE playlist_id = :file AND track_id IN (" . implode(',', array_map('intval', $selection)) . ")
        ");
        $req->execute([':file' => $fileId]);
    }

    if ($position === 'suivant' && !$remplacer) {
        $depart = 1;
        if ($apres !== null) {
            $req = $pdo->prepare("SELECT position FROM track__playlist WHERE playlist_id = :file AND track_id = :t");
            $req->execute([':file' => $fileId, ':t' => $apres]);
            $courante = $req->fetchColumn();
            if ($courante !== false) { $depart = (int) $courante + 1; }
        }
        // Place nette pour tout le lot d'un coup.
        $req = $pdo->prepare("
            UPDATE track__playlist SET position = position + :n
             WHERE playlist_id = :file AND position >= :depart
        ");
        $req->execute([':n' => count($selection), ':file' => $fileId, ':depart' => $depart]);
    } else {
        $req = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM track__playlist WHERE playlist_id = :file");
        $req->execute([':file' => $fileId]);
        $depart = (int) $req->fetchColumn();
    }

    $ins = $pdo->prepare("INSERT INTO track__playlist (track_id, playlist_id, position) VALUES (:t, :file, :p)");
    foreach ($selection as $i => $trackId) {
        $ins->execute([':t' => (int) $trackId, ':file' => $fileId, ':p' => $depart + $i]);
    }

    $pdo->commit();

    // La file complète revient : le lecteur remplace son état sans recharger.
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

    journalInfo('contenu', 'file_lot', "Lot d'étiquettes envoyé dans la liste d'attente", [
        'tags'      => $tagsDemandes,
        'genres'    => $genresDemandes,
        'mode'      => $mode,
        'position'  => $remplacer ? 'remplacement' : $position,
        'titres'    => count($selection),
    ]);

    $n = count($selection);
    echo json_encode([
        'success'     => true,
        'ajoutes'     => $n,
        'deplaces'    => $dejaLa,
        'message'     => $n . ($n > 1 ? ' titres' : ' titre')
                       . ($remplacer ? " — file remplacée" : " dans la liste d'attente"),
        'queue'       => $file,
        'playlist_id' => $fileId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echecJson('file_lot', $e, "Impossible de remplir la liste d'attente");
}
