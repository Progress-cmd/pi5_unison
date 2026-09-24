<?php
/**
 * Changement de mot de passe depuis le compte.
 *
 * Jusqu'ici le seul chemin passait par « mot de passe oublié » et un courriel,
 * alors même qu'on est déjà connecté.
 *
 * L'ancien mot de passe est exigé : sans lui, une session laissée ouverte sur
 * un appareil prêté suffirait à prendre le compte.
 *
 * Entrée POST : actuel, nouveau
 * Sortie JSON : { success, message }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$actuel  = (string) ($_POST['actuel'] ?? '');
$nouveau = (string) ($_POST['nouveau'] ?? '');

if ($actuel === '' || $nouveau === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Renseignez les deux champs']);
    exit;
}

// 10 caractères, comme la réinitialisation par courriel (reset_password.php) :
// les deux chemins mènent au même mot de passe, ils doivent exiger la même
// chose — sinon le plus permissif décide pour les deux.
if (mb_strlen($nouveau) < 10) {
    echo json_encode(['success' => false, 'message' => 'Le nouveau mot de passe doit faire au moins 10 caractères']);
    exit;
}

if ($nouveau === $actuel) {
    echo json_encode(['success' => false, 'message' => "Le nouveau mot de passe est identique à l'ancien"]);
    exit;
}

$userId = (int) $_SESSION['user']['id'];
$pdo = Config::getConnection();

try {
    $req = $pdo->prepare("SELECT `password-hash` FROM users WHERE id = :id");
    $req->execute([':id' => $userId]);
    $hash = $req->fetchColumn();

    if (!is_string($hash) || $hash === '' || !password_verify($actuel, $hash)) {
        journalAttention('auth', 'changement_mdp_refuse',
            'Mot de passe actuel incorrect lors d\'un changement',
            ['user_id' => $userId]);

        echo json_encode(['success' => false, 'message' => 'Mot de passe actuel incorrect']);
        exit;
    }

    /*
     * Le jeton de session est régénéré en même temps.
     *
     * Changer son mot de passe parce qu'on le croit compromis doit fermer les
     * sessions ouvertes ailleurs — sinon le changement ne protège de rien.
     * La session courante recopie le nouveau jeton et reste valide.
     */
    $jeton = bin2hex(random_bytes(32));

    $req = $pdo->prepare(
        "UPDATE users SET `password-hash` = :hash, jeton_session = :jeton WHERE id = :id"
    );
    $req->execute([
        ':hash'  => password_hash($nouveau, PASSWORD_DEFAULT),
        ':jeton' => $jeton,
        ':id'    => $userId,
    ]);

    $_SESSION['jeton_session'] = $jeton;

    journalInfo('auth', 'mot_de_passe_change',
        'Mot de passe modifié depuis le compte', ['user_id' => $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Mot de passe modifié. Les autres appareils ont été déconnectés.',
    ]);
} catch (Throwable $e) {
    echecJson('changer_mdp', $e, 'Modification impossible', 500, 'auth');
}
