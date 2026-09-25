<?php
/**
 * Marque une annonce comme vue par la session courante.
 *
 * C'est ce qui garantit le « une seule fois » : la clé primaire composée
 * (annonce_id, user_id) rend une seconde vue impossible, même si plusieurs
 * onglets ferment la popup au même instant. D'où le INSERT IGNORE — ce n'est
 * pas une erreur, c'est le comportement attendu.
 *
 * Entrée POST : annonce_id
 * Sortie JSON : { success }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);

header('Content-Type: application/json');

$annonceId = filter_input(INPUT_POST, 'annonce_id', FILTER_VALIDATE_INT);

if (!$annonceId) {
    echecJson('annonce_vue', null, 'Annonce inconnue', 400, 'admin');
    exit;
}

/*
 * En démonstration, rien n'est écrit : le compte est emprunté, et marquer une
 * annonce comme vue la ferait disparaître pour le vrai titulaire.
 */
if (estDemo()) {
    echo json_encode(['success' => true]);
    exit;
}

include_once "../includes/config.php";

try {
    $req = Config::getConnection()->prepare(
        "INSERT IGNORE INTO annonces_vues (annonce_id, user_id) VALUES (:a, :u)"
    );
    $req->execute([':a' => $annonceId, ':u' => (int) $_SESSION['user']['id']]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echecJson('annonce_vue', $e, 'Annonce non marquée', 500, 'admin');
}
