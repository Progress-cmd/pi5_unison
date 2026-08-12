<?php
include_once "../includes/auth.php";
include_once "../includes/rateLimit.php";

demarrerSession();
verifierCsrf(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée');
}

/**
 * Entrée en mode démonstration : aucune vérification de mot de passe, mais la
 * session est marquée is_demo et toutes les actions d'écriture la refusent
 * (voir refuserSiDemo() dans includes/auth.php).
 */
if (filter_input(INPUT_POST, 'demo', FILTER_VALIDATE_BOOL)) {
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'        => DEMO_USER_ID,
        'username'  => DEMO_USERNAME,
        'email'     => null,
        'view_mode' => 'mixed',
        'is_demo'   => true,
        'role'      => 'user',   // une démonstration n'administre jamais rien
    ];

    header('Location: ../index.php');
    exit;
}

// 5 échecs par quart d'heure et par IP : suffisant pour deux utilisateurs
// légitimes, inutilisable pour un bruteforce.
if (($attente = rlBloque('login', 5, 900)) > 0) {
    http_response_code(429);
    header('Location: ../login.php?trop_de_tentatives=' . ceil($attente / 60));
    exit;
}

$password = $_POST['password'] ?? '';
$modeAdmin = ($_POST['mode'] ?? '') === 'admin';
$cle = null;

/** Échec : toujours le même message, jamais d'indice sur ce qui a manqué. */
function echecConnexion(bool $modeAdmin): never
{
    rlEchec('login');
    if ($modeAdmin) {
        rlEchec('login_admin', 1800);
    }
    header('Location: ../login.php?incorrect_password=true');
    exit;
}

if ($modeAdmin) {
    /*
     * Accès technique : l'identifiant est saisi à la main, il n'est pas proposé
     * par la page. Compteur dédié, plus strict que le compteur général — un
     * compte d'administration ne se trompe pas trois fois par demi-heure.
     */
    if (($attente = rlBloque('login_admin', 3, 1800)) > 0) {
        http_response_code(429);
        header('Location: ../login.php?trop_de_tentatives=' . ceil($attente / 60));
        exit;
    }

    $username = trim((string) ($_POST['identifiant'] ?? ''));
    if ($username === '') {
        echecConnexion(true);
    }
} else {
    // Le formulaire n'envoie qu'une clé (« tortue », « papillon ») : la
    // correspondance vers le nom d'utilisateur réel ne vit que côté serveur.
    $cle = filter_input(INPUT_POST, 'selectedUser', FILTER_DEFAULT);
    $username = usernameDepuisCle($cle);

    if ($username === null) {
        echecConnexion(false);
    }
}

include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT id, username, email, `password-hash`, view_mode, role FROM users WHERE username = :username");
$req->bindValue(':username', $username);
$req->execute();

$user = $req->fetch();

// Un hash vide en base ne doit jamais valider : on l'écarte avant password_verify.
$hash = $user['password-hash'] ?? '';
$valide = $user !== false && is_string($hash) && $hash !== '' && password_verify($password, $hash);

// Un compte désactivé garde ses données mais ne se connecte plus.
if ($valide && ($user['role'] ?? 'user') === 'desactive') {
    $valide = false;
}

/*
 * Le rôle est une CONDITION DE CONNEXION sur ce chemin, pas seulement une
 * condition d'accès. Sans ce test, le formulaire d'accès technique annulerait
 * toute l'anonymisation de la page publique : il suffirait d'y poster
 * « Francis » pour attaquer son compte en le nommant directement, alors que
 * le système de clés existe précisément pour l'empêcher.
 */
if ($valide && $modeAdmin && ($user['role'] ?? 'user') !== 'admin') {
    $valide = false;
}

if (!$valide) {
    echecConnexion($modeAdmin);
}

rlReussite('login');
if ($modeAdmin) {
    rlReussite('login_admin');
}

/*
 * Mémorise le compte pour le présélectionner la prochaine fois.
 * HttpOnly : c'est PHP qui rend la sélection, le JavaScript n'a rien à en
 * faire. Ce cookie ne contient qu'un nom déjà affiché sur la page de
 * connexion — il n'authentifie rien.
 *
 * Rien n'est posé pour un accès technique : un cookie trahirait l'existence
 * et la nature de ce compte.
 */
if (!$modeAdmin) {
    setcookie('unison_dernier_compte', $cle, [
        'expires'  => time() + 31536000, // un an
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

// Contre la fixation de session : l'identifiant change au moment où la session
// gagne des privilèges.
session_regenerate_id(true);

$_SESSION['user'] = [
    'id'        => $user['id'],
    'username'  => $user['username'],
    'email'     => $user['email'],
    'view_mode' => $user['view_mode'] ?? 'mixed',
    'is_demo'   => false,
    'role'      => $user['role'] ?? 'user',
];

header("Location: ../index.php");
exit;
