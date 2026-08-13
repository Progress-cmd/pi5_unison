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

    journalInfo('auth', 'connexion_demo', 'Entrée en mode démonstration');

    header('Location: ../index.php');
    exit;
}

// 5 échecs par quart d'heure et par IP : suffisant pour deux utilisateurs
// légitimes, inutilisable pour un bruteforce.
if (($attente = rlBloque('login', 5, 900)) > 0) {
    // Un blocage effectif est le signal qu'on cherche vraiment dans un journal :
    // il signifie que les échecs se sont enchaînés, pas qu'un mot de passe a été
    // tapé de travers.
    journalAttention('auth', 'connexion_bloquee',
        'Trop de tentatives : connexion bloquée temporairement',
        ['attente_s' => $attente, 'compteur' => 'login']);

    http_response_code(429);
    header('Location: ../login.php?trop_de_tentatives=' . ceil($attente / 60));
    exit;
}

$password = $_POST['password'] ?? '';
$modeAdmin = ($_POST['mode'] ?? '') === 'admin';
$cle = null;

/**
 * Échec : toujours le même message, jamais d'indice sur ce qui a manqué.
 *
 * La cause précise n'est pas dite à l'utilisateur, mais elle est écrite au
 * journal : c'est exactement ce qui manque pour distinguer, après coup, une
 * faute de frappe d'une tentative d'intrusion.
 */
function echecConnexion(bool $modeAdmin, string $cause, array $contexte = []): never
{
    rlEchec('login');
    if ($modeAdmin) {
        rlEchec('login_admin', 1800);
    }

    journalAttention('auth', 'connexion_echouee',
        'Tentative de connexion échouée : ' . $cause,
        $contexte + ['mode' => $modeAdmin ? 'admin' : 'normal']);

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
        journalAttention('auth', 'connexion_bloquee',
            "Trop de tentatives sur l'accès technique : connexion bloquée",
            ['attente_s' => $attente, 'compteur' => 'login_admin']);

        http_response_code(429);
        header('Location: ../login.php?trop_de_tentatives=' . ceil($attente / 60));
        exit;
    }

    $username = trim((string) ($_POST['identifiant'] ?? ''));
    if ($username === '') {
        echecConnexion(true, 'identifiant vide');
    }
} else {
    // Le formulaire n'envoie qu'une clé (« tortue », « papillon ») : la
    // correspondance vers le nom d'utilisateur réel ne vit que côté serveur.
    $cle = filter_input(INPUT_POST, 'selectedUser', FILTER_DEFAULT);
    $username = usernameDepuisCle($cle);

    if ($username === null) {
        // Aucune clé valide : le formulaire n'a pas été utilisé tel qu'il est
        // servi. Seule la clé reçue est journalisée, elle est publique.
        echecConnexion(false, 'clé de compte inconnue',
            ['cle_recue' => mb_substr((string) $cle, 0, 30)]);
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

// La cause n'est jamais dite à l'utilisateur — elle est précisée au fil des
// tests pour le seul journal, qui n'est lisible que par l'administration.
$cause = 'mot de passe incorrect ou compte inexistant';

// Un compte désactivé garde ses données mais ne se connecte plus.
if ($valide && ($user['role'] ?? 'user') === 'desactive') {
    $valide = false;
    $cause = 'compte désactivé';
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
    // Cas le plus révélateur du lot : quelqu'un a posté un nom d'utilisateur
    // réel sur le formulaire d'accès technique, en connaissant le mot de passe.
    $cause = "compte non administrateur sur l'accès technique";
}

if (!$valide) {
    echecConnexion($modeAdmin, $cause, ['compte_vise' => $username]);
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

// Journalisée après l'affectation de la session : la ligne porte ainsi le
// compte connecté, sans qu'il faille le repasser en contexte.
journalInfo('auth', 'connexion_reussie',
    'Connexion de « ' . $user['username'] . ' »',
    ['role' => $user['role'] ?? 'user', 'mode' => $modeAdmin ? 'admin' : 'normal']);

header("Location: ../index.php");
exit;
