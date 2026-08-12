<?php
include_once "../includes/auth.php";
include_once "../includes/rateLimit.php";

demarrerSession();

/**
 * L'autorisation repose sur le jeton du lien reçu par email, jamais sur un
 * champ contrôlé par le client : c'est lui, et lui seul, qui désigne le compte
 * à modifier. Le jeton CSRF ne protège que contre la soumission croisée.
 */
verifierCsrf(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée');
}

// Un lien de réinitialisation ne doit pas servir de canal de bruteforce.
if (($attente = rlBloque('reset', 10, 900)) > 0) {
    http_response_code(429);
    exit('Trop de tentatives. Réessayez dans ' . ceil($attente / 60) . ' minutes.');
}

$id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$lien  = filter_input(INPUT_POST, 'reset_token', FILTER_DEFAULT);
$mdp   = $_POST['password'] ?? '';
$mdp2  = $_POST['password_confirm'] ?? '';

if (!$id || !is_string($lien) || $lien === '') {
    rlEchec('reset');
    exit('Lien invalide');
}

if (!is_string($mdp) || strlen($mdp) < 10) {
    exit('Le mot de passe doit contenir au moins 10 caractères.');
}

if (!hash_equals($mdp, (string) $mdp2)) {
    exit('Les deux mots de passe ne correspondent pas.');
}

include_once "../includes/config.php";
$pdo = Config::getConnection();

// Le jeton est stocké haché : on rejoue le hachage pour retrouver la ligne.
$req = $pdo->prepare(
    "SELECT id FROM users
     WHERE id = :id AND reset_token = :token AND reset_token_expires > NOW()"
);
$req->execute([
    ':id'    => $id,
    ':token' => hash('sha256', $lien),
]);

if (!$req->fetchColumn()) {
    rlEchec('reset');
    error_log("Réinitialisation refusée : jeton invalide ou expiré pour l'id $id");
    exit('Lien expiré ou invalide');
}

// Le jeton est consommé dans la même requête que le changement de mot de passe :
// un lien ne peut donc servir qu'une seule fois.
$maj = $pdo->prepare(
    "UPDATE users
     SET `password-hash` = :hash, reset_token = NULL, reset_token_expires = NULL
     WHERE id = :id"
);
$maj->execute([
    ':hash' => password_hash($mdp, PASSWORD_DEFAULT),
    ':id'   => $id,
]);

rlReussite('reset');

// Session repartie de zéro : toute session ouverte avec l'ancien mot de passe
// (y compris celle d'un éventuel intrus) est invalidée.
$_SESSION = [];
session_regenerate_id(true);

header('Location: ../login.php?reset_password=true');
exit;
