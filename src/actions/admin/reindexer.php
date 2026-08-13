<?php
/**
 * Reconstruction des index MeiliSearch à la demande.
 *
 * Utile après une suppression massive, une correction de titres, ou si la
 * recherche remonte des résultats qui n'existent plus. L'indexation est
 * normalement faite au démarrage du conteneur ; rien ne permettait de la
 * relancer sans redémarrer.
 *
 * Entrée POST : token
 * Sortie JSON : { success, message, rapport }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

require_once '../../../vendor/autoload.php';
require_once '../../includes/initSearch.php';

use Meilisearch\Client;

// Chaque lot d'index attend la fin des tâches MeiliSearch : c'est lent.
@set_time_limit(300);
session_write_close();

try {
    $client = new Client('http://ms:7700', getenv('MS_PASS') ?: null);
    $resultat = reindexerTout($client);
} catch (\Exception $e) {
    // La recherche est un service à part entière : son indisponibilité dégrade
    // l'application sans l'arrêter, d'où le niveau « critique ».
    journalCritique('recherche', 'reindexation_echouee',
        'MeiliSearch injoignable pendant la réindexation',
        ['erreur' => $e->getMessage()]);

    error_log('reindexer : ' . $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => "MeiliSearch est injoignable — la recherche est peut-être arrêtée",
    ]);
    exit;
}

$message = sprintf('Index reconstruits : %d titres, %d artistes',
    $resultat['musiques'], $resultat['artistes']);

journalInfo('recherche', 'reindexation', $message,
    ['musiques' => $resultat['musiques'], 'artistes' => $resultat['artistes']]);

echo json_encode([
    'success' => true,
    'message' => $message,
    'rapport' => $resultat['rapport'],
]);
