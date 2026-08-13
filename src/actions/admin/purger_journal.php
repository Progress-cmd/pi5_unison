<?php
/**
 * Purge manuelle du journal : supprime les événements plus vieux que N jours.
 *
 * L'entretien courant est automatique (journalPurgeAutomatique, déclenché par
 * la consultation de la section). Cette action sert à reprendre de la place
 * tout de suite, ou à raccourcir la rétention ponctuellement.
 *
 * Entrée POST : jours, token
 * Sortie JSON : { success, message, supprimes, restants }
 */
include_once "../../includes/auth.php";
include_once "../../includes/journalRapport.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

$pdo = Config::getConnectionPrincipale();

if (!journalTableExiste($pdo)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => "La table du journal n'existe pas : appliquez la migration 002.",
    ]);
    exit;
}

$jours = filter_input(INPUT_POST, 'jours', FILTER_VALIDATE_INT);

/*
 * Une purge « à 0 jour » viderait toute la table, y compris l'événement en
 * train d'être écrit. journalPurger() ramène déjà toute valeur à 1 minimum ;
 * on refuse ici explicitement, plutôt que d'exécuter silencieusement autre
 * chose que ce qui a été demandé.
 */
if ($jours === false || $jours === null || $jours < 1) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "Indiquez un nombre de jours à conserver (au moins 1)",
    ]);
    exit;
}

try {
    $supprimes = journalPurger($pdo, $jours);
    $restants  = journalCompter($pdo);
} catch (PDOException $e) {
    error_log('purger_journal : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Purge impossible']);
    exit;
}

$message = $supprimes > 0
    ? "$supprimes événement(s) supprimé(s), $restants conservé(s)"
    : "Aucun événement de plus de $jours jour(s) — rien à supprimer";

// Journalisé APRÈS la purge : la trace de l'entretien ne doit pas être
// emportée par l'entretien lui-même.
journalInfo('admin', 'journal_purge', $message,
    ['retention_jours' => $jours, 'supprimes' => $supprimes, 'restants' => $restants]);

echo json_encode([
    'success'   => true,
    'message'   => $message,
    'supprimes' => $supprimes,
    'restants'  => $restants,
]);
