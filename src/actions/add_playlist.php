<?php
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";
include_once "../includes/rendu.php";   // playlistSysteme()

header('Content-Type: application/json');

/*
 * Le jeton à usage unique qui était exigé ici en plus du CSRF a disparu.
 *
 * Il faisait double emploi : csrf.js pose « csrf » sur toute écriture et
 * verifierCsrf() le contrôle déjà. Surtout, il venait d'un champ caché que
 * seule la page de création possédait, ce qui interdisait d'appeler cette
 * action d'ailleurs — or elle sert désormais aussi au sélecteur « Ajouter à
 * une playlist ». Au passage, la page de création écrasait $_SESSION['token'],
 * celui-là même dont l'import se sert.
 */

$nom = trim((string) filter_input(INPUT_POST, 'name', FILTER_DEFAULT));

if ($nom === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nom invalide']);
    exit;
}

/*
 * 50 et non 100 : c'est la taille de la colonne. À 100, un nom long était
 * accepté puis tronqué par MariaDB — la playlist existait sous un autre nom
 * que celui demandé.
 */
if (mb_strlen($nom) > 50) {
    http_response_code(400);
    echo json_encode(['success' => false,
        'message' => 'Nom trop long (50 caractères au maximum)']);
    exit;
}

// Les noms système désignent la file et les favoris : les laisser créer à la
// main ferait apparaître deux listes que l'application croit uniques.
if (playlistSysteme($nom)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Ce nom est réservé']);
    exit;
}

$pdo = Config::getConnection();
$moi = (int) $_SESSION['user']['id'];

// Deux playlists du même nom chez la même personne ne se distinguent plus
// dans le sélecteur.
$req = $pdo->prepare("
    SELECT id FROM playlists WHERE `created-by_id` = :user AND name = :name
");
$req->execute([':user' => $moi, ':name' => $nom]);
if ($req->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Vous avez déjà une playlist de ce nom']);
    exit;
}

try {
    $req = $pdo->prepare("INSERT INTO playlists (name, `created-by_id`) VALUES (:name, :user)");
    $req->execute([':name' => $nom, ':user' => $moi]);

    /*
     * lastInsertId() AVANT toute autre requête sur cette connexion : le
     * journal écrit sur la même, et c'est exactement ce qui nous avait déjà
     * mordus (voir le terminal SQL).
     */
    $id = (int) $pdo->lastInsertId();

    journalInfo('contenu', 'playlist_creee', 'Playlist créée',
        ['playlist' => $id, 'nom' => $nom]);

    echo json_encode([
        'success'  => true,
        // Rendu par du texte, jamais par innerHTML : un nom de playlist est
        // saisi par l'utilisateur.
        'message'  => 'Playlist « ' . $nom . ' » créée',
        'playlist' => ['id' => $id, 'name' => $nom, 'nom' => libellePlaylist($nom)],
    ]);
} catch (Exception $e) {
    echecJson('creer_playlist', $e, "Impossible de créer la playlist");
}
exit;
