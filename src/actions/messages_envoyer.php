<?php
/**
 * Envoi d'un message au second membre du foyer.
 *
 * Un message porte un texte, un titre joint, ou les deux — mais jamais rien :
 * partager une musique sans un mot est un usage courant (« regarde ce son »),
 * envoyer du vide n'en est pas un.
 *
 * Entrée POST : contenu (facultatif), track_id (facultatif)
 * Sortie JSON : { success, message, envoye: { … } }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);

header('Content-Type: application/json');

$moi = (int) $_SESSION['user']['id'];
$destinataire = idPartenaire();

if ($destinataire === null) {
    echecJson('messages_envoyer', null, 'Aucun destinataire', 400);
    exit;
}

$contenu = trim((string) filter_input(INPUT_POST, 'contenu', FILTER_DEFAULT));
$trackId = filter_input(INPUT_POST, 'track_id', FILTER_VALIDATE_INT) ?: null;

if ($contenu === '' && $trackId === null) {
    echecJson('messages_envoyer', null, 'Message vide', 400);
    exit;
}

// La colonne accepte 2000 caractères : on coupe ici pour répondre une erreur
// claire plutôt que de laisser MariaDB tronquer en silence.
if (mb_strlen($contenu) > 2000) {
    echecJson('messages_envoyer', null, 'Message trop long (2000 caractères maximum)', 400);
    exit;
}

include_once "../includes/config.php";

try {
    $pdo = Config::getConnection();

    /*
     * Le titre joint est vérifié avant l'insertion. La clé étrangère refuserait
     * bien un identifiant inventé, mais avec une erreur SQL générique : autant
     * dire ce qui ne va pas.
     */
    if ($trackId !== null) {
        $req = $pdo->prepare("SELECT id FROM tracks WHERE id = :id");
        $req->execute([':id' => $trackId]);

        if ($req->fetchColumn() === false) {
            echecJson('messages_envoyer', null, "Ce titre n'existe plus", 404);
            exit;
        }
    }

    $req = $pdo->prepare("
        INSERT INTO messages (expediteur_id, destinataire_id, contenu, track_id)
        VALUES (:moi, :dest, :contenu, :track)
    ");
    $req->execute([
        ':moi'     => $moi,
        ':dest'    => $destinataire,
        ':contenu' => $contenu !== '' ? $contenu : null,
        ':track'   => $trackId,
    ]);

    /*
     * lastInsertId() AVANT toute autre requête sur cette connexion : le journal
     * écrit sur la même, et le dernier identifiant inséré serait alors le sien.
     * C'est le piège documenté dans CLAUDE.md, et il a déjà mordu ici.
     */
    $id = (int) $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Message envoyé',
        'envoye'  => ['id' => $id],
    ]);
} catch (Throwable $e) {
    echecJson('messages_envoyer', $e, 'Message non envoyé');
}
