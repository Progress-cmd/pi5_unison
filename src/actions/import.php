<?php
/**
 * Import unitaire, après confirmation des métadonnées dans le formulaire.
 *
 * Ce fichier recopiait auparavant, sur près de deux cents lignes, ce que fait
 * importTrackFromUrl() : téléchargement yt-dlp, insertion du titre, des
 * artistes, des genres et indexation Meilisearch. Les deux copies avaient
 * divergé — celle-ci n'avait ni les pauses anti-limitation de yt-dlp, ni la
 * traduction des erreurs, ni les traces d'échec — si bien qu'un import
 * unitaire se faisait limiter par YouTube là où l'import en masse passait.
 *
 * Il ne reste ici que ce qui lui est propre : le jeton à usage unique du
 * formulaire, et les métadonnées telles que l'utilisateur les a corrigées.
 *
 * Entrée POST : token, title, artist, duration, url, miniature, genre
 * Sortie JSON : { success, message, track_id }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);

header('Content-Type: application/json');

/*
 * Jeton à usage unique, propre au formulaire d'import : il vaut aussi comme
 * garde anti-CSRF, et se consomme, ce qui empêche de rejouer l'import en
 * rechargeant la page. Il ne passe donc pas par verifierCsrf().
 */
if (
    !isset($_POST['token'], $_SESSION['token']) ||
    !hash_equals($_SESSION['token'], (string) $_POST['token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token invalide']);
    exit;
}
unset($_SESSION['token']);

// On capture l'utilisateur puis on libère le verrou de session : le
// téléchargement qui suit est long et ne doit pas bloquer la navigation
// ni la lecture audio dans les autres onglets/requêtes.
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
session_write_close();

require '../../vendor/autoload.php';
require_once '../includes/ytImport.php';
include_once '../includes/config.php';

$url = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);

/*
 * Les métadonnées viennent du formulaire, pas d'une seconde interrogation de
 * yt-dlp : l'utilisateur a pu les corriger avant de valider, et ce sont
 * celles-là qui doivent être enregistrées.
 */
$meta = [
    'title'     => trim((string) filter_input(INPUT_POST, 'title',  FILTER_DEFAULT)),
    'artist'    => trim((string) filter_input(INPUT_POST, 'artist', FILTER_DEFAULT)),
    'duration'  => (int) filter_input(INPUT_POST, 'duration', FILTER_SANITIZE_NUMBER_INT),
    'miniature' => filter_input(INPUT_POST, 'miniature', FILTER_VALIDATE_URL) ?: '',
    'genre'     => trim((string) (filter_input(INPUT_POST, 'genre', FILTER_DEFAULT) ?? '')),
];

if (!$meta['title'] || !$meta['artist'] || !$url) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Champs obligatoires manquants']);
    exit;
}

$pdo = Config::getConnection();
$res = importTrackFromUrl($pdo, $url, $meta, $currentUserId, false);

http_response_code($res['success'] ? 200 : 500);
echo json_encode([
    'success'  => $res['success'],
    'message'  => $res['message'],
    'track_id' => $res['track_id'],
]);
