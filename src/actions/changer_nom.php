<?php
/**
 * Changement du nom affiché.
 *
 * Le nom sert d'identifiant de connexion et de clé unique : il ne peut donc
 * pas être décoré librement. Il apparaît aussi dans le cercle de l'en-tête,
 * réduit à son initiale, et chez l'autre membre du foyer.
 *
 * Entrée POST : nom
 * Sortie JSON : { success, message, nom }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);

header('Content-Type: application/json');

$nom = trim((string) filter_input(INPUT_POST, 'nom', FILTER_DEFAULT));

/*
 * Bornes : la colonne accepte 50 caractères, et un nom vide rendrait le compte
 * impossible à désigner — à la connexion comme dans l'en-tête.
 */
if (mb_strlen($nom) < 2 || mb_strlen($nom) > 50) {
    echecJson('changer_nom', null, 'Le nom doit faire entre 2 et 50 caractères', 400, 'auth');
    exit;
}

/*
 * Pas de caractères de contrôle ni de retours à la ligne : le nom est affiché
 * partout, et il sert de secret pour le compte d'administration — un nom
 * contenant des espaces invisibles serait intaponnable à la connexion.
 */
if (preg_match('/[\x00-\x1F\x7F]/u', $nom)) {
    echecJson('changer_nom', null, 'Le nom contient des caractères interdits', 400, 'auth');
    exit;
}

$moi = (int) $_SESSION['user']['id'];

try {
    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    // Contrôle explicite plutôt que de laisser remonter l'erreur d'unicité :
    // le message doit dire ce qui se passe, pas citer une contrainte SQL.
    $req = $pdo->prepare("SELECT id FROM users WHERE username = :nom AND id != :moi");
    $req->execute([':nom' => $nom, ':moi' => $moi]);

    if ($req->fetchColumn() !== false) {
        echecJson('changer_nom', null, 'Ce nom est déjà pris', 409, 'auth');
        exit;
    }

    $ancien = (string) ($_SESSION['user']['username'] ?? '');

    $req = $pdo->prepare("UPDATE users SET username = :nom WHERE id = :moi");
    $req->execute([':nom' => $nom, ':moi' => $moi]);
} catch (Throwable $e) {
    echecJson('changer_nom', $e, 'Nom non enregistré', 500, 'auth');
    exit;
}

$_SESSION['user']['username'] = $nom;

journalInfo('auth', 'nom_change',
    'Nom affiché changé : « ' . $ancien . ' » → « ' . $nom . ' »',
    ['compte_id' => $moi]);

echo json_encode([
    'success' => true,
    'message' => 'Nom enregistré',
    'nom'     => $nom,
]);
