<?php
/**
 * Suppression définitive d'un titre : ligne en base, fichier audio, document
 * de recherche.
 *
 * Entrée POST : track_id, token
 * Sortie JSON : { success, message, fichier_supprime }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
if (!$trackId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Identifiant invalide']);
    exit;
}

$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT title, file FROM tracks WHERE id = :id");
$req->execute([':id' => $trackId]);
$titre = $req->fetch(PDO::FETCH_ASSOC);

if (!$titre) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Titre introuvable']);
    exit;
}

/*
 * Le nom de fichier vient de la base, mais on ne lui fait pas confiance pour
 * autant : une ligne corrompue ne doit pas pouvoir faire supprimer un fichier
 * arbitraire. On exige qu'il soit déjà réduit à son nom de base.
 */
$fichier = (string) $titre['file'];
if ($fichier !== '' && $fichier !== basename($fichier)) {
    error_log("supprimer_titre : nom de fichier suspect pour le titre $trackId : $fichier");
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Nom de fichier suspect en base, suppression refusée',
    ]);
    exit;
}

/*
 * Ordre volontaire : la base d'abord, les effets externes ensuite.
 * L'inverse laisserait, en cas d'échec SQL, une ligne pointant vers un fichier
 * disparu — un titre visible mais illisible, plus gênant qu'un fichier
 * orphelin, que la page Stockage sait détecter et nettoyer.
 *
 * Les tables de liaison (artist__track, track__genre, tag__track,
 * track__playlist, note__track, nb_listen, historical) sont toutes en
 * ON DELETE CASCADE : rien à supprimer à la main.
 */
try {
    $req = $pdo->prepare("DELETE FROM tracks WHERE id = :id");
    $req->execute([':id' => $trackId]);
} catch (PDOException $e) {
    error_log('supprimer_titre : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Suppression impossible en base']);
    exit;
}

// Fichier audio
$fichierSupprime = false;
if ($fichier !== '') {
    $chemin = Config::cheminMusiques() . $fichier;
    if (is_file($chemin)) {
        $fichierSupprime = @unlink($chemin);
        if (!$fichierSupprime) {
            error_log("supprimer_titre : fichier non supprimé ($chemin)");
        }
    }
}

// Document de recherche. Un index désynchronisé se répare par la réindexation
// (page Maintenance) : il ne doit pas faire échouer une suppression déjà commise.
$meili = clientMeili();
if ($meili) {
    try {
        $meili->index(Config::indexMeili('musiques'))->deleteDocument($trackId);
    } catch (\Exception $e) {
        error_log('supprimer_titre / Meilisearch : ' . $e->getMessage());
    }
}

echo json_encode([
    'success'          => true,
    'message'          => 'Titre « ' . $titre['title'] . ' » supprimé',
    'fichier_supprime' => $fichierSupprime,
]);
