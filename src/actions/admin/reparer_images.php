<?php
/**
 * Diagnostic ou réparation des pochettes et photos d'artistes, depuis
 * l'interface d'administration.
 *
 * La logique vit dans includes/reparerImages.php, partagée avec le point
 * d'entrée en ligne de commande : les deux voies donnent exactement le même
 * résultat.
 *
 * Entrée POST : appliquer (0/1), token
 * Sortie JSON : { success, message, rapport }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";
include_once "../../includes/reparerImages.php";

header('Content-Type: application/json');
exigerAdmin(true);
verifierCsrf(true);

$appliquer = filter_input(INPUT_POST, 'appliquer', FILTER_VALIDATE_BOOL) ?? false;

// Le diagnostic est en lecture seule : autorisé en démonstration, contrairement
// à la réparation, qui écrit.
if ($appliquer) {
    refuserSiDemo(true);
}

/*
 * Chaque image inconnue déclenche un appel réseau (Deezer, YouTube) avec
 * temporisation : sur un gros catalogue l'opération dure. La session est déjà
 * fermée par exigerAdmin, elle ne bloque donc pas la navigation.
 */
@set_time_limit(300);
session_write_close();

$resultat = reparerImages(Config::getConnection(), $appliquer);

$message = $appliquer
    ? sprintf('%d artiste(s) et %d titre(s) corrigés', $resultat['artistes'], $resultat['titres'])
    : sprintf('%d artiste(s) et %d titre(s) réparables', $resultat['artistes'], $resultat['titres']);

echo json_encode([
    'success' => true,
    'message' => $message,
    'rapport' => $resultat['rapport'],
]);
