<?php
/**
 * Fil d'activité du foyer : ce qui a changé dans l'application, et par qui.
 *
 * Six sources réunies en une seule liste chronologique — titres importés,
 * albums, playlists, notes, favoris d'artistes et messages. Aucune table
 * d'événements n'est tenue à jour : tout est reconstitué à la lecture, à
 * partir des horodatages que chaque table porte déjà. Une table dédiée aurait
 * demandé d'instrumenter chaque action existante, et se serait désynchronisée
 * au premier oubli.
 *
 * À ne pas confondre avec `journal`, qui est le journal technique réservé à
 * l'administration : celui-ci raconte la vie du contenu, pas les incidents.
 *
 * Entrée  : offset (>= 0), limite (1..100)
 * Sortie JSON : { success, evenements: [{ type, acteur, ts, libelle, cible_id }] }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
$limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT);

// LIMIT et OFFSET n'acceptent pas de paramètre lié (préparation native) :
// bornés ici, puis interpolés en entiers.
$offset = ($offset !== false && $offset !== null && $offset > 0) ? $offset : 0;
$limite = ($limite !== false && $limite !== null) ? max(1, min(100, $limite)) : 30;

session_write_close();

try {
    $pdo = Config::getConnection();

    /*
     * Chaque branche produit la même forme : type, acteur, instant, libellé de
     * la cible, identifiant de la cible. Le tri et la pagination s'appliquent
     * ensuite à l'union — d'où la sous-requête, sans laquelle LIMIT ne
     * porterait que sur la dernière branche.
     *
     * Chaque branche est elle-même bornée à `$plafond` lignes.
     *
     * Sans cette borne, l'union matérialisait toutes les tables avant de
     * trier : le coût grandissait avec la discothèque et le chat, pour ne
     * jamais rendre plus de trente lignes. Comme le résultat final est trié
     * par date décroissante, une branche ne peut pas contribuer au-delà de ses
     * `offset + limite` lignes les plus récentes — les suivantes seraient de
     * toute façon écartées. Mesuré : 29 ms → 3 ms avec 20 000 messages.
     *
     * L'instant sort en epoch : MariaDB est en UTC, le navigateur affiche dans
     * son propre fuseau.
     */
    $plafond = $offset + $limite;
    $sql = "
        SELECT * FROM (
            (SELECT 'titre' AS type, u.username AS acteur,
                    UNIX_TIMESTAMP(t.`created-at`) AS ts,
                    t.title AS libelle, t.id AS cible_id, t.img AS img
               FROM tracks t JOIN users u ON u.id = t.`added-by_id`
              ORDER BY t.`created-at` DESC LIMIT $plafond)

            UNION ALL

            (SELECT 'album', u.username, UNIX_TIMESTAMP(a.`created-at`),
                    a.title, a.id, a.img
               FROM albums a JOIN users u ON u.id = a.`added-by_id`
              ORDER BY a.`created-at` DESC LIMIT $plafond)

            UNION ALL

            (SELECT 'playlist', u.username, UNIX_TIMESTAMP(p.`created-at`),
                    p.name, p.id, NULL
               FROM playlists p JOIN users u ON u.id = p.`created-by_id`
              ORDER BY p.`created-at` DESC LIMIT $plafond)

            UNION ALL

            /*
             * Une note porte sur un titre ou sur une playlist : les deux
             * jointures sont donc facultatives, et le libellé prend celle qui
             * a répondu. Une note orpheline garde un libellé vide plutôt que
             * de disparaître du fil.
             */
            (SELECT 'note', u.username, UNIX_TIMESTAMP(n.`created-at`),
                    COALESCE(tn.title, pn.name, ''), n.id, tn.img
               FROM notes n
               JOIN users u ON u.id = n.`created-by_id`
               LEFT JOIN note__track nt ON nt.note_id = n.id
               LEFT JOIN tracks tn ON tn.id = nt.track_id
               LEFT JOIN note__playlist np ON np.note_id = n.id
               LEFT JOIN playlists pn ON pn.id = np.playlist_id
              ORDER BY n.`created-at` DESC LIMIT $plafond)

            UNION ALL

            (SELECT 'artiste_favori', u.username, UNIX_TIMESTAMP(f.`created-at`),
                    ar.name, ar.id, NULL
               FROM artist__favorite f
               JOIN users u ON u.id = f.user_id
               JOIN artists ar ON ar.id = f.artist_id
              ORDER BY f.`created-at` DESC LIMIT $plafond)

            UNION ALL

            /*
             * Le texte du message tient lieu de libellé. Le fil est commun aux
             * deux membres du foyer, qui voient de toute façon la conversation
             * entière : rien n'est dévoilé ici qui ne le soit déjà.
             */
            (SELECT 'message', u.username, UNIX_TIMESTAMP(m.cree_a),
                    COALESCE(m.contenu, ''), m.id, NULL
               FROM messages m JOIN users u ON u.id = m.expediteur_id
              ORDER BY m.cree_a DESC LIMIT $plafond)
        ) AS fil
        ORDER BY ts DESC, type ASC, cible_id DESC
        LIMIT $limite OFFSET $offset
    ";

    $evenements = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($evenements as &$e) {
        $e['ts']       = (int) $e['ts'];
        $e['cible_id'] = (int) $e['cible_id'];

        // Un message long est coupé : le fil donne à voir, la conversation
        // donne à lire.
        if ($e['type'] === 'message' && mb_strlen($e['libelle']) > 90) {
            $e['libelle'] = mb_substr($e['libelle'], 0, 89) . '…';
        }
    }
    unset($e);

    echo json_encode(['success' => true, 'evenements' => $evenements]);
} catch (Throwable $e) {
    echecJson('activite_lister', $e, 'Activité indisponible');
}
