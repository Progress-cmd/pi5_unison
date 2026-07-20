<?php
/**
 * Importe automatiquement un titre depuis une URL YouTube, sans étape de
 * confirmation (métadonnées déduites automatiquement). Appelé en boucle
 * par l'interface d'import multiple, une URL à la fois.
 *
 * Entrée POST : url
 * Sortie JSON : { success, message, title, artist, is_new }
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

require '../../vendor/autoload.php';
require_once '../includes/ytImport.php';
include_once '../includes/config.php';

@set_time_limit(600);

$url = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);
if (!$url) {
    echo json_encode(['success' => false, 'message' => 'URL invalide']);
    exit;
}

$meta = extractYtMetadata($url);
if ($meta === null) {
    echo json_encode(['success' => false, 'message' => 'Métadonnées introuvables', 'url' => $url]);
    exit;
}

$pdo = Config::getConnection();
$res = importTrackFromUrl($pdo, $url, $meta, (int) $_SESSION['user']['id']);

echo json_encode([
    'success' => $res['success'],
    'message' => $res['message'],
    'title'   => $res['title'],
    'artist'  => $res['artist'],
    'is_new'  => $res['is_new'],
]);
