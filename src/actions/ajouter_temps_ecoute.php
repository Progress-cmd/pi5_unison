<?php
header('Content-Type: application/json');
session_start();
include_once "../includes/config.php";

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

include_once "../includes/auth.php";

// Même logique que compter_ecoute.php : acquitté, mais non comptabilisé.
if (estDemo()) {
    echo json_encode(['success' => true, 'demo' => true]);
    exit;
}

$secondes = filter_input(INPUT_POST, 'secondes', FILTER_VALIDATE_INT);

// Borne anti-abus : un envoi couvre au plus une heure d'écoute
if (!$secondes || $secondes < 1 || $secondes > 3600) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$pdo = Config::getConnection();

try {
    $req = $pdo->prepare("UPDATE users SET `time-listened` = `time-listened` + :secondes WHERE id = :user_id");
    $req->execute([':secondes' => $secondes, ':user_id' => $_SESSION['user']['id']]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
