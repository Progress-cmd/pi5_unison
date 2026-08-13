<?php
/**
 * Déconnexion.
 *
 * session_destroy() seul ne suffit pas : il détruit le stockage serveur mais
 * laisse le tableau $_SESSION en mémoire pour la requête en cours, et surtout
 * laisse le cookie d'identifiant de session chez le client.
 */
include_once "../includes/auth.php";

demarrerSession();

// Avant la destruction : passé cette ligne, $_SESSION est vide et le journal
// ne saurait plus qui vient de partir.
if (isset($_SESSION['user']['id'])) {
    journalInfo('auth', 'deconnexion',
        'Déconnexion de « ' . ($_SESSION['user']['username'] ?? '?') . ' »');
}

$_SESSION = [];

// Suppression du cookie de session côté navigateur.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?: 'Lax',
    ]);
}

session_destroy();

// Directement vers la page de connexion : index.php n'aurait fait qu'y renvoyer.
header('Location: ../login.php');
exit;
