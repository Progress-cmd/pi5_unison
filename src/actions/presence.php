<?php
/**
 * Battement de présence : annonce mon état, renvoie celui des autres.
 *
 * Un seul aller-retour fait les deux — appelé toutes les 15 secondes par
 * chaque onglet ouvert, deux requêtes séparées doubleraient le trafic pour
 * rien.
 *
 * En GET, comme la consultation du journal : l'écriture qu'il produit est
 * l'enregistrement du fait que l'appelant est là, pas une action qu'on
 * pourrait lui faire déclencher à son insu. Un jeton CSRF n'y ajouterait
 * aucune garantie — la garde qui compte est exigerConnexion().
 *
 * Les paramètres sont lus dans la query string, y compris sur un POST : le
 * dernier battement, envoyé au moment de fermer la page, part par
 * navigator.sendBeacon(), qui émet un POST. Les lire via INPUT_GET couvre donc
 * les deux cas — ne pas les déplacer vers le corps de la requête.
 *
 * Entrée GET : track_id, en_ecoute, observer
 * Sortie JSON : { success, autres: [{ user_id, username, en_ligne, en_ecoute, titre, artiste }] }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

/*
 * Au-delà de 90 secondes sans battement, la personne est considérée partie :
 * six battements manqués à 15 s.
 *
 * Large exprès. Un téléphone écran éteint, en veille réseau, peut sauter
 * plusieurs battements d'affilée tout en jouant : avec une tolérance plus
 * courte, l'autre le voyait disparaître puis revenir en boucle — un
 * clignotement qui se lit comme une panne. Les départs propres n'attendent
 * pas ce délai : la fermeture d'onglet est annoncée par sendBeacon, et la
 * déconnexion supprime la ligne (actions/logout.php).
 */
const PRESENCE_DELAI = 90;

$moi = (int) $_SESSION['user']['id'];

/*
 * Le mode démonstration ne participe pas.
 *
 * Il emprunte l'identité d'un compte de la base de démonstration : le faire
 * apparaître « en ligne » ferait croire à une présence réelle, et la table
 * vit de toute façon dans l'autre base.
 */
if (estDemo()) {
    echo json_encode(['success' => true, 'autres' => []]);
    exit;
}

$trackId  = filter_input(INPUT_GET, 'track_id', FILTER_VALIDATE_INT) ?: null;
$enEcoute = (bool) filter_input(INPUT_GET, 'en_ecoute', FILTER_VALIDATE_BOOL);

/*
 * `observer=1` : lire sans s'annoncer.
 *
 * Un onglet passé en arrière-plan continue de rafraîchir son affichage, mais
 * ne doit plus entretenir sa propre présence — sinon un onglet oublié depuis
 * trois jours afficherait un point vert en permanence. Il lit donc l'état des
 * autres sans toucher à sa ligne, qui expire alors normalement.
 */
$observer = (bool) filter_input(INPUT_GET, 'observer', FILTER_VALIDATE_BOOL);

// Plus rien ne dépend de la session : on libère le verrou, ce battement
// revient toutes les 15 secondes et ne doit bloquer aucune autre requête.
session_write_close();

$pdo = Config::getConnection();

try {
    if (!$observer) {
        /*
         * Une seule ligne par compte, remplacée à chaque battement. `vu-a`
         * porte ON UPDATE current_timestamp() : il se met à jour tout seul, y
         * compris quand ni le titre ni l'état d'écoute n'ont changé — sans quoi
         * rester sur la même chanson ferait passer pour parti.
         */
        $req = $pdo->prepare("
            INSERT INTO presence (user_id, track_id, en_ecoute)
            VALUES (:moi, :track, :ecoute)
            ON DUPLICATE KEY UPDATE
                track_id  = VALUES(track_id),
                en_ecoute = VALUES(en_ecoute),
                `vu-a`    = current_timestamp()
        ");
        $req->execute([
            ':moi'    => $moi,
            ':track'  => $trackId,
            ':ecoute' => $enEcoute ? 1 : 0,
        ]);
    }

    // Le délai est interpolé : c'est une constante entière du code, jamais une
    // valeur reçue — et INTERVAL n'accepte pas de paramètre lié.
    $req = $pdo->prepare("
        SELECT users.id AS user_id, users.username,
               users.presence_visible, users.presence_partage_titre,
               presence.en_ecoute,
               TIMESTAMPDIFF(SECOND, presence.`vu-a`, NOW()) AS silence,
               tracks.title AS titre,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artiste
          FROM presence
          JOIN users ON users.id = presence.user_id
          LEFT JOIN tracks        ON tracks.id = presence.track_id
          LEFT JOIN artist__track ON artist__track.track_id = tracks.id
          LEFT JOIN artists       ON artists.id = artist__track.artist_id
         WHERE presence.user_id != :moi
           AND users.role != 'admin'
           AND users.presence_visible = 1
         GROUP BY users.id, users.username, users.presence_visible,
                  users.presence_partage_titre, presence.en_ecoute,
                  presence.`vu-a`, tracks.title
    ");
    $req->execute([':moi' => $moi]);

    $autres = [];
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $enLigne = (int) $l['silence'] <= PRESENCE_DELAI;

        /*
         * « Partager ce que j'écoute » désactivé : on reste visible, mais
         * l'écoute est tue. Le filtrage se fait ici et non côté client — une
         * préférence de discrétion qui n'existerait que dans le navigateur
         * d'en face ne protégerait rien.
         */
        $partage  = (bool) $l['presence_partage_titre'];
        $enEcoute = $enLigne && $partage && (bool) $l['en_ecoute'];

        $autres[] = [
            'user_id'   => (int) $l['user_id'],
            'username'  => $l['username'],
            'en_ligne'  => $enLigne,
            // Hors ligne, l'écoute n'a plus de sens : le dernier titre connu
            // ne doit pas donner l'illusion d'une lecture en cours.
            'en_ecoute' => $enEcoute,
            'titre'     => $enEcoute ? $l['titre'] : null,
            'artiste'   => $enEcoute ? $l['artiste'] : null,
        ];
    }

    echo json_encode(['success' => true, 'autres' => $autres]);
} catch (Throwable $e) {
    echecJson('presence', $e, 'Présence indisponible');
}
