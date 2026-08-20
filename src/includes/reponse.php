<?php
/**
 * Réponses JSON normalisées des actions.
 *
 * Treize actions renvoyaient au client le message brut de l'exception
 * (« Erreur: SQLSTATE[23000]… »), c'est-à-dire des noms de tables, de
 * colonnes et de contraintes. C'est une aide au débogage précieuse — mais
 * dans le journal, pas dans le navigateur.
 *
 * Inclus depuis auth.php, donc disponible dans toute action.
 */

/**
 * Journalise l'incident et répond une erreur générique.
 *
 * @param string $action   nom court de l'opération, pour le journal
 * @param string $message  ce que l'utilisateur doit lire
 * @param int    $code     statut HTTP
 */
function echecJson(
    string $action,
    ?Throwable $e = null,
    string $message = 'Une erreur est survenue',
    int $code = 500,
    string $canal = 'contenu'
): void {
    if ($e !== null) {
        journalErreur($canal, $action, $e->getMessage(), [
            'fichier' => journalFichierCourt($e->getFile()),
            'ligne'   => $e->getLine(),
        ]);
    }

    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
}

/**
 * Vérifie qu'une playlist appartient bien à la session courante.
 *
 * add_to_playlist, remove_track_from_playlist et reorder_tracks acceptaient
 * n'importe quel playlist_id : un compte pouvait vider les favoris d'un autre,
 * ou réordonner sa file d'attente, en changeant un nombre dans la requête.
 * edit_playlist était le seul à contrôler l'appartenance.
 *
 * Coupe l'exécution en 403 si la playlist n'est pas celle de l'utilisateur.
 *
 * @return string le nom de la playlist, utile aux appelants qui doivent
 *                distinguer les playlists système.
 */
function exigerPlaylistDeLUtilisateur(PDO $pdo, int $playlistId): string
{
    $req = $pdo->prepare("SELECT name, `created-by_id` FROM playlists WHERE id = :id");
    $req->execute([':id' => $playlistId]);
    $playlist = $req->fetch(PDO::FETCH_ASSOC);

    if (!$playlist) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Playlist introuvable']);
        exit;
    }

    if ((int) $playlist['created-by_id'] !== (int) ($_SESSION['user']['id'] ?? 0)) {
        journalAttention('contenu', 'playlist_etrangere',
            "Tentative de modification d'une playlist d'un autre compte",
            ['playlist' => $playlistId, 'proprietaire' => (int) $playlist['created-by_id']]);

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Cette playlist n'est pas la vôtre"]);
        exit;
    }

    return (string) $playlist['name'];
}
