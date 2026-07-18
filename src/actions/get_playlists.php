<?php
header('Content-Type: application/json');
session_start();
include_once "../includes/config.php";

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$pdo = Config::getConnection();

try {
    $req = $pdo->prepare("
        SELECT id, name
        FROM playlists
        WHERE `created-by_id` = :user_id AND name != 'Wait Tracks'
        ORDER BY name
    ");
    $req->execute([':user_id' => $_SESSION['user']['id']]);
    $playlists = $req->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'playlists' => $playlists]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
