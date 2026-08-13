<?php
/**
 * Exécute une commande de la console d'administration.
 *
 * L'interprétation vit dans includes/console.php ; ce fichier ne fait que
 * garder la porte, journaliser, et rendre la réponse.
 *
 * Le jeton CSRF est exigé bien que toutes les commandes soient en lecture
 * seule : « base demo » modifie la session, et surtout rien ne garantit qu'une
 * commande future le restera. La garde est posée une fois pour toutes.
 *
 * Entrée POST : commande, token
 * Sortie JSON : { success, blocs, base, duree_ms }
 */
include_once "../../includes/auth.php";

/*
 * Autoloader de Composer : sans lui, clientMeili() ne trouve pas la classe du
 * client et conclut que la recherche est indisponible. « sante » et « meili »
 * annonceraient alors une panne permanente. Chargé ici plutôt que dans
 * includes/console.php, que la page inclut aussi — elle n'a besoin que de la
 * liste des noms de commandes.
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

include_once "../../includes/console.php";

header('Content-Type: application/json');
exigerAdmin(true);
verifierCsrf(true);

/*
 * Pas de refuserSiDemo() : une session de démonstration n'est jamais
 * administratrice (voir estAdmin), exigerAdmin l'a donc déjà écartée. La
 * console est par ailleurs en lecture seule de bout en bout.
 */

$commande = trim((string) ($_POST['commande'] ?? ''));

/** Invite du terminal : elle rappelle en permanence la base interrogée. */
function consoleInvite(): string
{
    return 'unison:' . consoleBaseCourante() . ' $';
}

if ($commande === '') {
    echo json_encode([
        'success' => true,
        'blocs'   => [],
        'invite'  => consoleInvite(),
    ]);
    exit;
}

// Une commande démesurée ne peut venir que d'un collage malheureux : on refuse
// avant d'en faire quoi que ce soit.
if (mb_strlen($commande) > 2000) {
    http_response_code(413);
    echo json_encode([
        'success' => false,
        'blocs'   => [blocErreur('Commande trop longue (2000 caractères maximum).')],
        'invite'  => consoleInvite(),
    ]);
    exit;
}

$debut = hrtime(true);
$resultat = consoleExecuter($commande);
$duree = (int) ((hrtime(true) - $debut) / 1e6);

/*
 * Tout ce qui est tapé dans la console est journalisé, y compris les commandes
 * de simple consultation. C'est le principe d'une trace d'administration :
 * elle vaut surtout pour ce qu'on n'avait pas prévu de relire. Le niveau reste
 * « info » — la console ne modifie rien.
 */
journalInfo('console', 'commande',
    'Console : ' . mb_substr($commande, 0, 200),
    ['commande' => mb_substr($commande, 0, 500), 'base' => $resultat['base']]);

echo json_encode([
    'success'  => true,
    'blocs'    => $resultat['blocs'],
    'invite'   => consoleInvite(),
    'duree_ms' => $duree,
]);
