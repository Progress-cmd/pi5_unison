<?php
header('Content-Type: application/json');
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
include_once "../includes/config.php";

$name = isset($_POST['name']) ? trim($_POST['name']) : '';

if (!$name) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nom de tag vide']);
    exit;
}

$pdo = Config::getConnection();

try {
    // Vérifie si le tag existe déjà
    $req = $pdo->prepare("SELECT id FROM tags WHERE name = :name");
    $req->execute([':name' => $name]);
    $existing = $req->fetch();

    if ($existing) {
        echo json_encode(['success' => true, 'tag_id' => $existing['id'], 'message' => 'Tag existant']);
        exit;
    }

    // Crée le tag
    $req = $pdo->prepare("INSERT INTO tags (name) VALUES (:name)");
    $req->execute([':name' => $name]);
    $tagId = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'tag_id' => $tagId, 'message' => 'Tag créé']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
