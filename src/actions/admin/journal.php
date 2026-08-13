<?php
/**
 * Alimente la page Journal : une page d'événements filtrés, en JSON.
 *
 * En GET, contrairement aux autres actions de la section : c'est une lecture,
 * elle doit pouvoir être rejouée et rafraîchie sans conséquence. Il n'y a donc
 * pas de jeton CSRF à vérifier — un jeton protège d'une écriture déclenchée à
 * l'insu de l'utilisateur, ce qui n'a pas de sens ici. La garde qui compte,
 * exigerAdmin(), est bien présente.
 *
 * Entrée GET : niveau, canal, action, user_id, heures, recherche, page
 * Sortie JSON : { success, evenements, total, page, pages, par_page }
 */
include_once "../../includes/auth.php";
include_once "../../includes/journalRapport.php";

header('Content-Type: application/json');
exigerAdmin(true);

$pdo = Config::getConnectionPrincipale();

if (!journalTableExiste($pdo)) {
    echo json_encode([
        'success'  => false,
        'migration' => true,
        'message'  => "La table du journal n'existe pas : la migration 002 n'a pas été appliquée.",
    ]);
    exit;
}

/*
 * Les filtres ne sont pas validés ici : journalClauses() ignore purement et
 * simplement ce qu'elle ne reconnaît pas (niveau ou canal hors liste), et
 * n'assemble jamais de SQL à partir des valeurs reçues. Un paramètre farfelu
 * donne donc une liste non filtrée, jamais une erreur ni une injection.
 */
$filtres = [
    'niveau'    => (string) filter_input(INPUT_GET, 'niveau', FILTER_DEFAULT),
    'canal'     => (string) filter_input(INPUT_GET, 'canal', FILTER_DEFAULT),
    'action'    => (string) filter_input(INPUT_GET, 'action', FILTER_DEFAULT),
    'user_id'   => filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: null,
    'heures'    => filter_input(INPUT_GET, 'heures', FILTER_VALIDATE_INT) ?: null,
    'recherche' => trim((string) filter_input(INPUT_GET, 'recherche', FILTER_DEFAULT)),
];

$page = max(1, (int) (filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1));

try {
    $total = journalCompter($pdo, $filtres);
    $evenements = journalLister($pdo, $filtres, $page);
} catch (PDOException $e) {
    error_log('admin/journal : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lecture du journal impossible']);
    exit;
}

/*
 * Mise en forme côté serveur de ce qui demande du contexte PHP : l'affichage
 * relatif de la date, et le décodage du contexte JSON. Le navigateur reçoit
 * ainsi des données prêtes à poser, sans refaire ce travail dans chaque ligne.
 */
foreach ($evenements as &$evenement) {
    $evenement['quand'] = journalQuand($evenement['horodatage']);
    $evenement['canal_libelle'] = journalLibelleCanal($evenement['canal']);
    $evenement['contexte'] = $evenement['contexte'] === null
        ? null
        : json_decode($evenement['contexte'], true);
}
unset($evenement);

echo json_encode([
    'success'    => true,
    'evenements' => $evenements,
    'total'      => $total,
    'page'       => $page,
    'pages'      => max(1, (int) ceil($total / JOURNAL_PAR_PAGE)),
    'par_page'   => JOURNAL_PAR_PAGE,
]);
