<?php
/**
 * Suppression définitive d'un compte, avec réattribution de son contenu.
 *
 * Les clés étrangères vers `users` sont restrictives : `tracks.added-by_id` et
 * `playlists.created-by_id` empêchent la suppression tant que le compte a
 * importé un titre ou créé une playlist, et `historical.listened-by_id` est en
 * NO ACTION. Il faut donc réattribuer explicitement, ce qui est aussi une
 * sécurité : on ne supprime pas un compte sans décider où va son contenu.
 *
 * La désactivation (changer_role.php) reste préférable dans presque tous les
 * cas — elle conserve tout et se défait.
 *
 * Entrée POST : user_id, repreneur_id, confirmation (= username exact), token
 * Sortie JSON : { success, message }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

$userId      = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$repreneurId = filter_input(INPUT_POST, 'repreneur_id', FILTER_VALIDATE_INT);
$confirmation = trim((string) ($_POST['confirmation'] ?? ''));

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Identifiant invalide']);
    exit;
}

$moi = (int) $_SESSION['user']['id'];
if ($userId === $moi) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Vous ne pouvez pas supprimer votre propre compte']);
    exit;
}

$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT username, role FROM users WHERE id = :id");
$req->execute([':id' => $userId]);
$cible = $req->fetch(PDO::FETCH_ASSOC);

if (!$cible) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Compte introuvable']);
    exit;
}

// Le nom exact doit être retapé : une suppression de compte ne doit pas
// pouvoir résulter d'un clic mal placé.
if ($confirmation !== $cible['username']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Confirmation incorrecte : le nom saisi ne correspond pas',
    ]);
    exit;
}

if ($cible['role'] === 'admin') {
    $restants = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($restants <= 1) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "C'est le dernier administrateur : il ne peut pas être supprimé",
        ]);
        exit;
    }
}

// Le repreneur doit exister et être un autre compte.
if (!$repreneurId || $repreneurId === $userId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Choisissez un compte repreneur pour les titres et playlists',
    ]);
    exit;
}

$req = $pdo->prepare("SELECT username FROM users WHERE id = :id");
$req->execute([':id' => $repreneurId]);
$repreneur = $req->fetchColumn();

if ($repreneur === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Compte repreneur introuvable']);
    exit;
}

/*
 * Tout en une transaction : un échec en cours de route laisserait sinon un
 * compte à moitié dépouillé, avec une partie de son contenu déjà transférée.
 */
try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE tracks SET `added-by_id` = :repreneur WHERE `added-by_id` = :cible")
        ->execute([':repreneur' => $repreneurId, ':cible' => $userId]);

    $pdo->prepare("UPDATE playlists SET `created-by_id` = :repreneur WHERE `created-by_id` = :cible")
        ->execute([':repreneur' => $repreneurId, ':cible' => $userId]);

    // L'historique est personnel : il n'a aucun sens transféré, on le purge.
    // (sa clé étrangère est en NO ACTION, il bloquerait la suppression)
    $pdo->prepare("DELETE FROM historical WHERE `listened-by_id` = :id")
        ->execute([':id' => $userId]);

    // notes et nb_listen sont en ON DELETE CASCADE : rien à faire.
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("supprimer_compte #$userId : " . $e->getMessage());
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Suppression impossible : il reste du contenu rattaché à ce compte. '
                   . 'Préférez la désactivation.',
    ]);
    exit;
}

// Opération la plus lourde de conséquences de toute la section : elle efface
// un compte et déplace tout son contenu. Elle est journalisée en « attention »
// pour rester visible dans le filtre par défaut de la page Journal.
journalAttention('admin', 'compte_supprime',
    'Compte « ' . $cible['username'] . ' » supprimé, contenu réattribué à « ' . $repreneur . ' »',
    [
        'compte_id'    => $userId,
        'compte'       => $cible['username'],
        'role'         => $cible['role'],
        'repreneur_id' => $repreneurId,
        'repreneur'    => $repreneur,
    ]);

error_log("Compte #{$userId} ({$cible['username']}) supprimé par {$_SESSION['user']['username']}, "
        . "contenu réattribué à #{$repreneurId} ($repreneur)");

echo json_encode([
    'success' => true,
    'message' => 'Compte « ' . $cible['username'] . ' » supprimé, contenu réattribué à « ' . $repreneur . ' »',
]);
