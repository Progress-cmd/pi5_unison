<?php
/**
 * Renvoie une tranche de la discothèque, du plus récent au plus ancien.
 *
 * Sert la page « Tous les titres », qui charge par paquets : afficher les
 * centaines de pistes d'un coup fige la page sur mobile.
 *
 * Entrée  : offset (>= 0), limite (1..100)
 * Sortie JSON : { success, total, offset, tracks: [...] }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

// Rien ici ne dépend de la session au-delà de la garde : on rend la main tout
// de suite, la page enchaîne les paquets pendant le défilement.
session_write_close();

$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
$limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT);

// LIMIT et OFFSET n'acceptent pas de paramètre lié (préparation native) :
// les bornes sont donc vérifiées ici, puis interpolées en entiers.
$offset = ($offset !== false && $offset !== null && $offset > 0) ? $offset : 0;
$limite = ($limite !== false && $limite !== null) ? max(1, min(100, $limite)) : 30;

$pdo = Config::getConnection();

$total = (int) $pdo->query("SELECT COUNT(*) FROM tracks")->fetchColumn();

$req = $pdo->query("
    SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
           GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
    FROM tracks
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists       ON artists.id = artist__track.artist_id
    GROUP BY tracks.id, tracks.title, tracks.img, tracks.duration, tracks.`created-at`
    ORDER BY tracks.`created-at` DESC, tracks.id DESC
    LIMIT $limite OFFSET $offset
");

echo json_encode([
    'success' => true,
    'total'   => $total,
    'offset'  => $offset,
    'tracks'  => $req->fetchAll(PDO::FETCH_ASSOC),
]);
