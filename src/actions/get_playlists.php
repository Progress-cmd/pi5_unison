<?php
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

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
    echecJson('lister_playlists', $e, "Impossible de charger les playlists");
}
