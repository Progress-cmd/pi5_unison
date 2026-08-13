<?php
/**
 * Exécute une ligne du terminal SQL.
 *
 * L'interprétation et tous les contrôles vivent dans includes/sqlTerminal.php ;
 * ce fichier garde la porte et rend la réponse.
 *
 * Entrée POST : commande, token
 * Sortie JSON : { success, blocs, invite, ecriture, duree_ms }
 */
include_once "../../includes/auth.php";

// Autoloader de Composer : sqlTerminal.php charge console.php, dont les
// commandes réutilisées (\tables, \d) touchent au client MeiliSearch.
require_once __DIR__ . '/../../../vendor/autoload.php';

include_once "../../includes/sqlTerminal.php";

header('Content-Type: application/json');
exigerAdmin(true);
verifierCsrf(true);

/*
 * refuserSiDemo() serait redondant : une session de démonstration n'est jamais
 * administratrice (voir estAdmin), exigerAdmin l'a donc déjà écartée.
 */

$commande = trim((string) ($_POST['commande'] ?? ''));

if ($commande === '') {
    echo json_encode([
        'success'  => true,
        'blocs'    => [],
        'invite'   => sqlInvite(),
        'ecriture' => sqlEcritureActive(),
    ]);
    exit;
}

// Une requête démesurée ne peut venir que d'un collage malheureux.
if (mb_strlen($commande) > 10000) {
    http_response_code(413);
    echo json_encode([
        'success'  => false,
        'blocs'    => [blocErreur('Requête trop longue (10 000 caractères maximum).')],
        'invite'   => sqlInvite(),
        'ecriture' => sqlEcritureActive(),
    ]);
    exit;
}

/*
 * Une requête peut légitimement être longue (jointure sur tout l'historique).
 * max_statement_time la borne côté MariaDB ; on donne ici à PHP de quoi
 * attendre cette borne sans être coupé avant elle.
 */
@set_time_limit(60);

$debut = hrtime(true);
$resultat = sqlExecuter($commande);
$duree = (int) ((hrtime(true) - $debut) / 1e6);

// La journalisation détaillée est faite par sqlTerminal.php, au plus près de
// ce qui est exécuté : elle distingue lecture, écriture et refus.

echo json_encode([
    'success'  => true,
    'blocs'    => $resultat['blocs'],
    'invite'   => $resultat['invite'],
    'ecriture' => $resultat['ecriture'],
    'duree_ms' => $duree,
]);
