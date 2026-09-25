<?php
/**
 * Fil de discussion du foyer, du plus récent au plus ancien.
 *
 * Deux usages dans la même action :
 *   - sans `depuis`, une tranche paginée (ouverture du chat, remontée du fil) ;
 *   - avec `depuis`, seulement ce qui est arrivé après cet identifiant, pour
 *     rafraîchir un chat déjà ouvert sans tout retélécharger.
 *
 * Marque au passage comme lus les messages reçus, sauf si `marquer=0` : le
 * rafraîchissement d'un onglet en arrière-plan ne doit pas effacer la pastille
 * de non-lus alors que personne ne regarde.
 *
 * Entrée  : offset, limite (1..100), depuis (id), marquer (0|1)
 * Sortie JSON : { success, total, messages: [...] }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$moi = (int) $_SESSION['user']['id'];

if (estDemo() || idPartenaire() === null) {
    echo json_encode(['success' => true, 'total' => 0, 'messages' => []]);
    exit;
}

$offset  = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
$limite  = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT);
$depuis  = filter_input(INPUT_GET, 'depuis', FILTER_VALIDATE_INT);
$marquer = filter_input(INPUT_GET, 'marquer', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

// LIMIT et OFFSET n'acceptent pas de paramètre lié (préparation native) :
// bornés ici, puis interpolés en entiers.
$offset = ($offset !== false && $offset !== null && $offset > 0) ? $offset : 0;
$limite = ($limite !== false && $limite !== null) ? max(1, min(100, $limite)) : 40;
$depuis = ($depuis !== false && $depuis !== null && $depuis > 0) ? $depuis : null;

session_write_close();

try {
    $pdo = Config::getConnection();

    /*
     * Le fil est commun aux deux comptes : tout ce qui part de moi ou m'est
     * adressé. Avec deux membres, la condition est redondante — elle ne l'est
     * plus dès qu'un troisième compte existe, et elle coûte un index.
     */
    $portee = "(m.expediteur_id = :moi OR m.destinataire_id = :moi2)";

    $req = $pdo->prepare("SELECT COUNT(*) FROM messages m WHERE $portee");
    $req->execute([':moi' => $moi, ':moi2' => $moi]);
    $total = (int) $req->fetchColumn();

    /*
     * Un placeholder nommé ne peut pas apparaître deux fois en préparation
     * native : d'où :moi et :moi2, qui portent la même valeur.
     */
    $params = [':moi' => $moi, ':moi2' => $moi];
    $filtre = '';

    if ($depuis !== null) {
        $filtre = ' AND m.id > :depuis';
        $params[':depuis'] = $depuis;
    }

    $req = $pdo->prepare("
        SELECT m.id, m.expediteur_id, m.contenu, m.track_id,
               UNIX_TIMESTAMP(m.cree_a) AS cree_ts,
               m.lu_a IS NOT NULL AS lu,
               tracks.title AS titre, tracks.img AS img, tracks.duration AS duree,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artistes
          FROM messages m
          LEFT JOIN tracks        ON tracks.id = m.track_id
          LEFT JOIN artist__track ON artist__track.track_id = tracks.id
          LEFT JOIN artists       ON artists.id = artist__track.artist_id
         WHERE $portee $filtre
         GROUP BY m.id, m.expediteur_id, m.contenu, m.track_id, m.cree_a, m.lu_a,
                  tracks.title, tracks.img, tracks.duration
         ORDER BY m.id DESC
         LIMIT $limite OFFSET $offset
    ");
    $req->execute($params);

    // Rendus dans l'ordre de lecture : le plus ancien d'abord, comme un chat.
    $messages = array_reverse($req->fetchAll(PDO::FETCH_ASSOC));

    if ($marquer !== false) {
        $req = $pdo->prepare("
            UPDATE messages SET lu_a = current_timestamp()
             WHERE destinataire_id = :moi AND lu_a IS NULL
        ");
        $req->execute([':moi' => $moi]);
    }

    echo json_encode([
        'success'  => true,
        'total'    => $total,
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    echecJson('messages_lister', $e, 'Messages indisponibles');
}
