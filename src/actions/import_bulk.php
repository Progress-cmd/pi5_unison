<?php
/**
 * Importe automatiquement un titre depuis une URL YouTube, sans étape de
 * confirmation (métadonnées déduites automatiquement). Appelé en boucle
 * par l'interface d'import multiple, une URL à la fois.
 *
 * Entrée POST : url
 * Sortie JSON : { success, message, title, artist, is_new }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

// L'import est long (téléchargement + conversion WAV). On lit l'utilisateur
// puis on libère immédiatement le verrou de session, sinon toutes les autres
// requêtes (navigation, lecture audio) resteraient bloquées jusqu'à la fin.
$userId = (int) $_SESSION['user']['id'];
session_write_close();

require '../../vendor/autoload.php';
require_once '../includes/ytImport.php';
include_once '../includes/config.php';

@set_time_limit(600);

$url = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);
if (!$url) {
    echo json_encode(['success' => false, 'message' => 'URL invalide']);
    exit;
}

$raison = null;
$meta = extractYtMetadata($url, $raison);
if ($meta === null) {
    echo json_encode([
        'success' => false,
        'message' => $raison ?: 'Métadonnées introuvables',
        'url'     => $url,
    ]);
    exit;
}

$pdo = Config::getConnection();
$res = importTrackFromUrl($pdo, $url, $meta, $userId);

echo json_encode([
    'success' => $res['success'],
    'message' => $res['message'],
    'title'   => $res['title'],
    'artist'  => $res['artist'],
    'is_new'  => $res['is_new'],
    'url'     => $url,
]);
