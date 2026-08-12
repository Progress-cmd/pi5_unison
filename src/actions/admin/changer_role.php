<?php
/**
 * Change le rôle d'un compte : membre, administrateur ou désactivé.
 *
 * La désactivation est la façon recommandée de « retirer » un compte : elle
 * conserve l'historique et le contenu, elle est réversible, et elle bloque la
 * connexion sans dépendre d'une suppression que les clés étrangères refusent
 * de toute façon dès qu'un titre a été importé.
 *
 * Entrée POST : user_id, role, token
 * Sortie JSON : { success, message, role }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

const ROLES_VALIDES = ['user', 'admin', 'desactive'];

$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$role   = (string) filter_input(INPUT_POST, 'role', FILTER_DEFAULT);

if (!$userId || !in_array($role, ROLES_VALIDES, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

$moi = (int) $_SESSION['user']['id'];
$pdo = Config::getConnection();

// Se rétrograder soi-même est le meilleur moyen de se verrouiller dehors.
if ($userId === $moi) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Vous ne pouvez pas modifier votre propre rôle',
    ]);
    exit;
}

$req = $pdo->prepare("SELECT username, role FROM users WHERE id = :id");
$req->execute([':id' => $userId]);
$cible = $req->fetch(PDO::FETCH_ASSOC);

if (!$cible) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Compte introuvable']);
    exit;
}

// Il doit toujours rester au moins un administrateur actif.
if ($cible['role'] === 'admin' && $role !== 'admin') {
    $restants = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($restants <= 1) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "C'est le dernier administrateur : il ne peut pas être rétrogradé",
        ]);
        exit;
    }
}

/*
 * Désactivation : le hash est vidé en plus du rôle. actions/login.php écarte
 * déjà tout hash vide avant password_verify, la connexion est donc refusée par
 * deux mécanismes indépendants. Les jetons de réinitialisation en cours sont
 * annulés, sinon un mail déjà reçu permettrait de reprendre la main.
 */
if ($role === 'desactive') {
    $req = $pdo->prepare(
        "UPDATE users SET role = 'desactive', `password-hash` = '',
                          reset_token = NULL, reset_token_expires = NULL
         WHERE id = :id"
    );
    $req->execute([':id' => $userId]);

    echo json_encode([
        'success' => true,
        'role'    => 'desactive',
        'message' => 'Compte « ' . $cible['username'] . ' » désactivé. '
                   . 'Son contenu est conservé ; réactivez-le en lui redonnant un mot de passe.',
    ]);
    exit;
}

/*
 * Réactiver un compte désactivé ne suffit pas à le rendre utilisable : son
 * hash a été vidé. On le dit explicitement plutôt que de laisser découvrir que
 * la connexion échoue toujours.
 */
$req = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
$req->execute([':role' => $role, ':id' => $userId]);

$message = 'Compte « ' . $cible['username'] . ' » : rôle ' . $role;
if ($cible['role'] === 'desactive') {
    $message .= ". Attention : il faut encore lui définir un mot de passe pour qu'il puisse se connecter.";
}

echo json_encode(['success' => true, 'role' => $role, 'message' => $message]);
