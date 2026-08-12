<?php
/**
 * État de la dernière mise à jour, tel que publié par le script de l'hôte.
 * Interrogé en boucle par la page de maintenance tant qu'une mise à jour est
 * en cours.
 *
 * Entrée : aucune (GET ou POST)
 * Sortie JSON : { success, disponible, etat, demande_en_cours }
 */
include_once "../../includes/auth.php";
include_once "../../includes/majConteneurs.php";

header('Content-Type: application/json');
exigerAdmin(true);

// Lecture seule : pas de verrou de session à garder pendant un sondage
// répété toutes les cinq secondes.
session_write_close();

echo json_encode([
    'success'          => true,
    'disponible'       => majDisponible(),
    'etat'             => majEtat(),
    'demande_en_cours' => majDemandeEnCours() !== null,
]);
