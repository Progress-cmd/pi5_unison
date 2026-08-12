<?php
header('Content-Type: application/json');
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
include_once "../includes/config.php";

$noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT);

if (!$noteId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

try {
    // Vérifie que la note existe et que l'utilisateur en est l'auteur ou admin
    $req = $pdo->prepare("SELECT `created-by_id` FROM notes WHERE id = :id");
    $req->execute([':id' => $noteId]);
    $note = $req->fetch();

    if (!$note) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Note introuvable']);
        exit;
    }

    // Supprime la note
    $req = $pdo->prepare("DELETE FROM notes WHERE id = :id");
    $req->execute([':id' => $noteId]);

    // Supprime les associations
    $req = $pdo->prepare("DELETE FROM note__playlist WHERE note_id = :id");
    $req->execute([':id' => $noteId]);

    $req = $pdo->prepare("DELETE FROM note__track WHERE note_id = :id");
    $req->execute([':id' => $noteId]);

    echo json_encode(['success' => true, 'message' => 'Note supprimée']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
