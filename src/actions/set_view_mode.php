<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);

header('Content-Type: application/json');

$mode = filter_input(INPUT_POST, 'mode', FILTER_DEFAULT);
if ($mode !== 'mixed' && $mode !== 'personal') {
    echo json_encode(['success' => false, 'message' => 'Mode invalide']);
    exit;
}

// En démonstration la bascule reste utilisable, mais elle ne vit que le temps
// de la session : rien n'est écrit sur le compte emprunté.
if (!estDemo()) {
    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("UPDATE users SET view_mode = :mode WHERE id = :id");
    $req->execute([':mode' => $mode, ':id' => $_SESSION['user']['id']]);
}

// Met à jour le cache de session
$_SESSION['user']['view_mode'] = $mode;

echo json_encode(['success' => true, 'mode' => $mode]);
