<?php
header('Content-Type: application/json');
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
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

    // Le contrôle annoncé par le commentaire ci-dessus n'était pas fait :
    // l'auteur était lu puis ignoré, si bien que n'importe quel compte pouvait
    // supprimer la note d'un autre.
    if ((int) $note['created-by_id'] !== (int) $_SESSION['user']['id'] && !estAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => "Cette note n'est pas la vôtre"]);
        exit;
    }

    // Les liaisons note__playlist et note__track sont en ON DELETE CASCADE :
    // supprimer la note suffit.
    $req = $pdo->prepare("DELETE FROM notes WHERE id = :id");
    $req->execute([':id' => $noteId]);

    echo json_encode(['success' => true, 'message' => 'Note supprimée']);
} catch (Exception $e) {
    // Le détail va aux logs, pas au client : il expose la structure de la base.
    error_log('delete_note: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}
