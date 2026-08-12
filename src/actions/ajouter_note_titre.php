<?php
header('Content-Type: application/json');
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
include_once "../includes/config.php";

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
$text = isset($_POST['text']) ? trim($_POST['text']) : '';

if (!$trackId || !$text) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

// Vérifie que le titre existe
$req = $pdo->prepare("SELECT id FROM tracks WHERE id = :id");
$req->execute([':id' => $trackId]);
if (!$req->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Titre introuvable']);
    exit;
}

try {
    // Crée la note
    $req = $pdo->prepare("INSERT INTO notes (text, `created-by_id`) VALUES (:text, :user_id)");
    $req->execute([':text' => $text, ':user_id' => $_SESSION['user']['id']]);
    $noteId = $pdo->lastInsertId();

    // Associe la note au titre
    $req = $pdo->prepare("INSERT INTO note__track (note_id, track_id) VALUES (:note_id, :track_id)");
    $req->execute([':note_id' => $noteId, ':track_id' => $trackId]);

    echo json_encode(['success' => true, 'message' => 'Note ajoutée']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
