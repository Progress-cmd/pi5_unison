<?php
header('Content-Type: application/json');
session_start();
include_once "../includes/config.php";

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

$name = isset($_POST['name']) ? mb_substr(trim($_POST['name']), 0, 50) : '';

if (!$name) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nom de genre vide']);
    exit;
}

$pdo = Config::getConnection();

try {
    // Vérifie si le genre existe déjà
    $req = $pdo->prepare("SELECT id FROM genres WHERE name = :name");
    $req->execute([':name' => $name]);
    $existing = $req->fetch();

    if ($existing) {
        echo json_encode(['success' => true, 'genre_id' => $existing['id'], 'message' => 'Genre existant']);
        exit;
    }

    // Crée le genre
    $req = $pdo->prepare("INSERT INTO genres (name) VALUES (:name)");
    $req->execute([':name' => $name]);
    $genreId = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'genre_id' => $genreId, 'message' => 'Genre créé']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
