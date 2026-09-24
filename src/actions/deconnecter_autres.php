<?php
/**
 * Ferme toutes les sessions du compte, sauf celle qui le demande.
 *
 * Les sessions PHP vivent dans des fichiers sans lien avec le compte : on ne
 * peut pas les retrouver pour les supprimer. On invalide donc le jeton que
 * chacune porte — voir sessionToujoursValide() dans auth.php.
 *
 * Sortie JSON : { success, message }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$userId = (int) $_SESSION['user']['id'];
$pdo = Config::getConnection();

try {
    $jeton = bin2hex(random_bytes(32));

    $req = $pdo->prepare("UPDATE users SET jeton_session = :jeton WHERE id = :id");
    $req->execute([':jeton' => $jeton, ':id' => $userId]);

    // La session courante adopte le nouveau jeton : elle survit, les autres
    // tombent à leur requête suivante.
    $_SESSION['jeton_session'] = $jeton;

    journalInfo('auth', 'sessions_fermees',
        'Déconnexion des autres appareils', ['user_id' => $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Les autres appareils ont été déconnectés',
    ]);
} catch (Throwable $e) {
    echecJson('deconnecter_autres', $e, 'Opération impossible', 500, 'auth');
}
