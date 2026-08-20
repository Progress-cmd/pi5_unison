<?php
/**
 * Titres restant à convertir vers le format unique de l'application.
 *
 * Sortie JSON : { success, total, octets, octets_lisible, manquants, titres: [...] }
 */
include_once "../../includes/auth.php";
exigerAdmin(true);
verifierCsrf(true);
include_once "../../includes/config.php";
include_once "../../includes/adminOutils.php";
include_once "../../includes/conversionAudio.php";

header('Content-Type: application/json');

$pdo = Config::getConnection();
$analyse = conversionLister($pdo);

echo json_encode([
    'success'        => true,
    'total'          => count($analyse['titres']),
    'octets'         => $analyse['octets'],
    'octets_lisible' => formaterOctets($analyse['octets']),
    'manquants'      => $analyse['manquants'],
    'titres'         => $analyse['titres'],
]);
