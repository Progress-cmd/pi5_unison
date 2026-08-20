<?php
/**
 * Convertit le fichier d'UN titre vers le format unique de l'application.
 *
 * Un titre par appel, la page enchaînant les demandes : cent cinquante
 * conversions dans une seule requête dépasseraient tout délai raisonnable, et
 * une coupure ferait tout perdre. Découpé ainsi, chaque titre converti est
 * acquis, et relancer l'opération reprend là où elle s'était arrêtée.
 *
 * Entrée POST : track_id
 * Sortie JSON : { success, message, avant, apres }
 */
include_once "../../includes/auth.php";
exigerAdmin(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../../includes/config.php";
include_once "../../includes/conversionAudio.php";

header('Content-Type: application/json');

// Un titre long peut demander plusieurs dizaines de secondes d'encodage.
if (function_exists('set_time_limit')) { @set_time_limit(300); }

$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);

if (!$trackId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
}

// La session n'est plus consultée : on libère le verrou, l'encodage est long
// et bloquerait la navigation et la lecture audio.
session_write_close();

$pdo = Config::getConnection();

echo json_encode(conversionTitre($pdo, $trackId));
