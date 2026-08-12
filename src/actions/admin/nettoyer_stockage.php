<?php
/**
 * Suppression des fichiers audio orphelins.
 *
 * Un orphelin est un fichier présent sur le disque que plus aucune ligne de
 * `tracks` ne référence — reste d'un import interrompu ou d'une suppression
 * faite à la main en base.
 *
 * Entrée POST : fichier (facultatif : un seul), sinon tous les orphelins ; token
 * Sortie JSON : { success, message, supprimes, octets_liberes }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

$pdo = Config::getConnection();
$dossier = Config::cheminMusiques();

/*
 * La liste des orphelins est TOUJOURS recalculée ici, à partir du disque et de
 * la base. Le client peut demander à en supprimer un en particulier, mais son
 * nom est cherché dans cette liste : jamais utilisé pour construire un chemin.
 * Un nom fourni qui n'est pas un orphelin réel est simplement ignoré.
 */
$analyse = analyserStockage($pdo);
$orphelins = $analyse['orphelins'];

$cible = trim((string) ($_POST['fichier'] ?? ''));
if ($cible !== '') {
    $orphelins = array_values(array_filter($orphelins, fn($o) => $o['fichier'] === $cible));

    if (!$orphelins) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "Ce fichier n'est pas (ou n'est plus) un orphelin",
        ]);
        exit;
    }
}

if (!$orphelins) {
    echo json_encode(['success' => true, 'message' => 'Aucun fichier orphelin', 'supprimes' => 0]);
    exit;
}

$supprimes = 0;
$octets = 0;
$echecs = [];

foreach ($orphelins as $orphelin) {
    // basename() par acquit de conscience : le nom vient de scandir(), il est
    // donc déjà sûr, mais le chemin ne doit dépendre d'aucune hypothèse.
    $chemin = $dossier . basename($orphelin['fichier']);

    if (@unlink($chemin)) {
        $supprimes++;
        $octets += $orphelin['octets'];
    } else {
        $echecs[] = $orphelin['fichier'];
        error_log("nettoyer_stockage : échec de suppression ($chemin)");
    }
}

$message = "$supprimes fichier(s) supprimé(s), " . formaterOctets($octets) . " libéré(s)";
if ($echecs) {
    $message .= ' — ' . count($echecs) . ' échec(s), voir les logs';
}

echo json_encode([
    'success'        => $echecs === [],
    'message'        => $message,
    'supprimes'      => $supprimes,
    'octets_liberes' => $octets,
]);
