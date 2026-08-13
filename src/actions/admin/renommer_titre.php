<?php
/**
 * Correction du titre d'un morceau.
 *
 * Les métadonnées déduites par yt-dlp sont souvent approximatives : c'est la
 * correction la plus courante, et rien ne permettait de la faire jusqu'ici
 * (modifier_titre.php, malgré son nom, ne touche que les genres et étiquettes).
 *
 * Entrée POST : track_id, titre, token
 * Sortie JSON : { success, message, titre }
 */
include_once "../../includes/auth.php";
include_once "../../includes/adminOutils.php";

header('Content-Type: application/json');
exigerAdmin(true);
refuserSiDemo(true);
verifierCsrf(true);

$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT);
$titre   = trim((string) ($_POST['titre'] ?? ''));

if (!$trackId || $titre === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Titre vide ou identifiant invalide']);
    exit;
}

// La colonne fait 50 caractères : on tronque proprement plutôt que de laisser
// MariaDB le faire (ou lever une erreur en mode strict).
$titre = mb_substr($titre, 0, 50);

$pdo = Config::getConnection();

$req = $pdo->prepare("UPDATE tracks SET title = :titre WHERE id = :id");
$req->execute([':titre' => $titre, ':id' => $trackId]);

if ($req->rowCount() === 0) {
    // rowCount vaut 0 si le titre n'existe pas, mais aussi si la valeur est
    // inchangée : on distingue les deux pour ne pas afficher une fausse erreur.
    $existe = $pdo->prepare("SELECT 1 FROM tracks WHERE id = :id");
    $existe->execute([':id' => $trackId]);
    if (!$existe->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Titre introuvable']);
        exit;
    }
}

// L'index de recherche doit suivre, sinon l'ancien titre reste trouvable.
$meili = clientMeili();
if ($meili) {
    try {
        $meili->index(Config::indexMeili('musiques'))->addDocuments([[
            'id_music'    => $trackId,
            'title_music' => $titre,
        ]]);
    } catch (\Exception $e) {
        error_log('renommer_titre / Meilisearch : ' . $e->getMessage());
    }
}

journalInfo('contenu', 'titre_renomme',
    'Titre #' . $trackId . ' renommé en « ' . $titre . ' »',
    ['track_id' => $trackId, 'titre' => $titre]);

echo json_encode(['success' => true, 'message' => 'Titre corrigé', 'titre' => $titre]);
