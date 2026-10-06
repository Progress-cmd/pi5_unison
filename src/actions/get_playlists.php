<?php
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";
// libellePlaylist() / playlistSysteme() : rendu.php n'est pas tiré par auth.php.
include_once "../includes/rendu.php";

header('Content-Type: application/json');

$pdo = Config::getConnection();
$moi = (int) ($_SESSION['user']['id'] ?? 0);

/*
 * track_id est facultatif. Quand il est fourni, chaque playlist indique si
 * elle contient déjà ce titre : c'est ce qui permet au sélecteur d'afficher
 * des cases à cocher plutôt qu'un « + » qui ne dit jamais où le titre se
 * trouve déjà.
 */
$titre = filter_input(INPUT_GET, 'track_id', FILTER_VALIDATE_INT) ?: null;

try {
    /*
     * On liste désormais les playlists des DEUX comptes, pas seulement les
     * siennes : ajouter un titre chez l'autre est le but de ce changement.
     *
     * Deux exclusions, et elles n'ont pas la même raison :
     *   - « Wait Tracks » n'est pas une playlist mais la file de lecture ;
     *     elle a ses propres commandes et n'a rien à faire ici ;
     *   - les playlists SYSTÈME des autres comptes, parce que les favoris de
     *     quelqu'un sont les siens. On ne décide pas de ce que l'autre aime.
     *
     * Les playlists ordinaires des autres comptes, elles, sont bien proposées.
     */
    $req = $pdo->prepare("
        SELECT playlists.id,
               playlists.name,
               playlists.`created-by_id` AS proprietaire_id,
               users.username            AS proprietaire,
               (SELECT COUNT(*) FROM track__playlist
                 WHERE track__playlist.playlist_id = playlists.id) AS nb_titres,
               " . ($titre
                    ? "(SELECT COUNT(*) FROM track__playlist
                         WHERE track__playlist.playlist_id = playlists.id
                           AND track__playlist.track_id = :track) > 0"
                    : "0") . " AS contient
        FROM playlists
        LEFT JOIN users ON users.id = playlists.`created-by_id`
        WHERE playlists.name <> 'Wait Tracks'
          AND (playlists.`created-by_id` = :moi_favoris OR playlists.name <> 'Favorite Tracks')
        ORDER BY (playlists.`created-by_id` = :moi_ordre) DESC, users.username, playlists.name
    ");
    /*
     * Trois occurrences, trois noms : les requêtes préparées sont natives
     * (ATTR_EMULATE_PREPARES = false), et un même paramètre nommé ne peut pas
     * être réutilisé dans une requête.
     */
    $req->bindValue(':moi_favoris', $moi, PDO::PARAM_INT);
    $req->bindValue(':moi_ordre', $moi, PDO::PARAM_INT);
    if ($titre) { $req->bindValue(':track', $titre, PDO::PARAM_INT); }
    $req->execute();

    $playlists = array_map(static function (array $p) use ($moi): array {
        return [
            'id'        => (int) $p['id'],
            'name'      => $p['name'],
            // Libellé affichable : « Favorite Tracks » se lit « Favoris ».
            'nom'       => libellePlaylist($p['name']),
            'nb_titres' => (int) $p['nb_titres'],
            'contient'  => (bool) $p['contient'],
            'systeme'   => playlistSysteme($p['name']),
            'mienne'    => (int) $p['proprietaire_id'] === $moi,
            // Sert à écrire « de Cassandre » dans le sélecteur.
            'proprietaire' => (string) ($p['proprietaire'] ?? ''),
        ];
    }, $req->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['success' => true, 'playlists' => $playlists]);
} catch (Exception $e) {
    echecJson('lister_playlists', $e, "Impossible de charger les playlists");
}
