<?php
/**
 * Définit un mot de passe temporaire pour un compte.
 *
 * Le mot de passe est généré côté serveur et renvoyé UNE SEULE FOIS, à
 * transmettre de vive voix : l'administrateur ne choisit pas le mot de passe
 * d'un autre, et rien n'est stocké en clair.
 *
 * Entrée POST : user_id, token
 * Sortie JSON : { success, message, mot_de_passe }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Identifiant invalide']);
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

/**
 * Mot de passe lisible et transmissible à l'oral : quatre groupes de quatre
 * caractères, sans les couples ambigus (0/O, 1/l/I).
 */
function motDePasseTemporaire(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;

    $groupes = [];
    for ($g = 0; $g < 4; $g++) {
        $groupe = '';
        for ($i = 0; $i < 4; $i++) {
            $groupe .= $alphabet[random_int(0, $max)];
        }
        $groupes[] = $groupe;
    }

    return implode('-', $groupes);
}

$motDePasse = motDePasseTemporaire();

// Les jetons de réinitialisation en cours sont annulés : un lien reçu par mail
// avant ce changement ne doit plus permettre de reprendre la main.
$req = $pdo->prepare(
    "UPDATE users SET `password-hash` = :hash, reset_token = NULL, reset_token_expires = NULL
     WHERE id = :id"
);
$req->execute([':hash' => password_hash($motDePasse, PASSWORD_DEFAULT), ':id' => $userId]);

// Le mot de passe généré n'apparaît évidemment nulle part dans la trace : on
// journalise le fait, pas le secret.
journalAttention('admin', 'mot_de_passe_reinitialise',
    'Mot de passe régénéré pour « ' . $cible['username'] . ' »',
    ['compte_id' => $userId, 'compte' => $cible['username'], 'role' => $cible['role']]);

error_log("Mot de passe réinitialisé pour #{$userId} ({$cible['username']}) par "
        . $_SESSION['user']['username']);

echo json_encode([
    'success'      => true,
    'message'      => 'Mot de passe régénéré pour « ' . $cible['username'] . ' »',
    'mot_de_passe' => $motDePasse,
]);
