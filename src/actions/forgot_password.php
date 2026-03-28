<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedUser = $_POST['selectedUser'] ?? null;
    $token = $_POST['token'] ?? null;

    // Vérifier le token
    if ($token !== $_SESSION['token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token invalide']);
        exit;
    }

    if (!$selectedUser) {
        echo json_encode(['success' => false, 'message' => 'Aucun utilisateur sélectionné']);
        exit;
    }

    // Récupérer l'email de l'utilisateur depuis la BD
    // $email = getEmailFromDatabase($selectedUser);

    // Envoyer l'email de réinitialisation
    // mail($email, 'Réinitialiser votre mot de passe', ...);

    // Répondre avec succès
    echo json_encode(['success' => true, 'message' => 'Email envoyé']);
}
?>