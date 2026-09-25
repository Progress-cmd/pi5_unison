<?php
/**
 * Renvoie une tranche de l'historique d'écoute, de la plus récente à la plus
 * ancienne.
 *
 * Sert la page « Historique d'écoute », qui charge par paquets : l'historique
 * grossit à chaque titre écouté et n'a pas de plafond, contrairement à la
 * discothèque.
 *
 * Toujours celui de la session : l'historique est personnel, et le mode
 * d'affichage « contenu commun » ne l'ouvre pas à l'autre membre du foyer.
 *
 * L'instant est renvoyé en epoch (UNIX_TIMESTAMP) et non en texte : MariaDB
 * tourne en UTC, PHP en Europe/Paris, et le navigateur peut être ailleurs
 * encore. Un horodatage absolu laisse le navigateur l'afficher dans le fuseau
 * de qui regarde — une chaîne « 2026-09-24 21:57:39 » aurait été lue comme
 * une heure locale, soit deux heures de moins que la réalité.
 *
 * Entrée  : offset (>= 0), limite (1..100)
 * Sortie JSON : { success, total, offset, ecoutes: [...] }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$moi = (int) $_SESSION['user']['id'];

// Rien ici ne dépend plus de la session : on rend la main tout de suite, la
// page enchaîne les paquets pendant le défilement.
session_write_close();

$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
$limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT);

// LIMIT et OFFSET n'acceptent pas de paramètre lié (préparation native) :
// les bornes sont donc vérifiées ici, puis interpolées en entiers.
$offset = ($offset !== false && $offset !== null && $offset > 0) ? $offset : 0;
$limite = ($limite !== false && $limite !== null) ? max(1, min(100, $limite)) : 30;

try {
    $pdo = Config::getConnection();

    $req = $pdo->prepare("SELECT COUNT(*) FROM historical WHERE `listened-by_id` = :moi");
    $req->execute([':moi' => $moi]);
    $total = (int) $req->fetchColumn();

    /*
     * Un titre supprimé emporte ses écoutes (clé étrangère), la jointure est
     * donc franche et non LEFT : une ligne d'historique sans titre n'existe
     * pas, et il n'y a rien à afficher pour elle.
     *
     * Le regroupement porte sur `listened-at` : deux écoutes du même titre à
     * des moments différents sont deux lignes, c'est tout l'intérêt d'un
     * historique.
     *
     * Le tri se départage sur l'identifiant du titre : deux écoutes à la même
     * seconde — deux comptes, ou un enchaînement rapide — laisseraient sinon
     * l'ordre au bon vouloir du moteur, et la pagination par OFFSET sauterait
     * ou répéterait une ligne d'un paquet à l'autre.
     */
    $req = $pdo->prepare("
        SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
               UNIX_TIMESTAMP(historical.`listened-at`) AS ecoute_ts,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
          FROM historical
          JOIN tracks ON tracks.id = historical.track_id
          LEFT JOIN artist__track ON artist__track.track_id = tracks.id
          LEFT JOIN artists       ON artists.id = artist__track.artist_id
         WHERE historical.`listened-by_id` = :moi
         GROUP BY historical.`listened-at`, tracks.id, tracks.title,
                  tracks.img, tracks.duration
         ORDER BY historical.`listened-at` DESC, tracks.id DESC
         LIMIT $limite OFFSET $offset
    ");
    $req->execute([':moi' => $moi]);

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'offset'  => $offset,
        'ecoutes' => $req->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    echecJson('lister_historique', $e, 'Historique indisponible');
}
