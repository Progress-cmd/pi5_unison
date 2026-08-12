<?php
/**
 * Dépose une demande de mise à jour des conteneurs.
 *
 * Voir includes/majConteneurs.php pour le mécanisme et ses limites : cet
 * endpoint n'exécute rien, il écrit un fichier que le script de l'hôte ramasse.
 *
 * Entrée POST : action (recharger|reconstruire), token
 * Sortie JSON : { success, message }
 */
include_once "../../includes/auth.php";
include_once "../../includes/majConteneurs.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

$action = (string) filter_input(INPUT_POST, 'action', FILTER_DEFAULT);

[$ok, $message] = majDeposerDemande($action, (string) $_SESSION['user']['username']);

if (!$ok) {
    http_response_code(409);
}

error_log("Demande de mise à jour « $action » par {$_SESSION['user']['username']} : "
        . ($ok ? 'déposée' : "refusée ($message)"));

echo json_encode(['success' => $ok, 'message' => $message]);
