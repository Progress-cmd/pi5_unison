<?php
/**
 * Enregistrement d'une préférence de compte, depuis la page Paramètres.
 *
 * Un seul réglage par appel : chaque interrupteur s'enregistre au basculement,
 * sans bouton « Valider » à cliquer. Le champ est choisi dans une liste fermée
 * — jamais interpolé dans le SQL, même s'il passe le filtre.
 *
 * Entrée POST : champ, valeur
 * Sortie JSON : { success, message, valeur }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);

header('Content-Type: application/json');

/*
 * Liste fermée : nom de colonne => validateur.
 *
 * La clé sert de nom de colonne dans la requête. C'est sûr parce qu'elle vient
 * de ce tableau et jamais de la requête HTTP : `$champs[$champ]` échoue avant
 * d'atteindre le SQL si le nom n'est pas connu.
 */
$champs = [
    'presence_visible'       => fn ($v) => $v === '1' || $v === '0' ? (int) $v : null,
    'presence_partage_titre' => fn ($v) => $v === '1' || $v === '0' ? (int) $v : null,
    'theme'                  => fn ($v) => in_array($v, ['clair', 'sombre', 'systeme'], true) ? $v : null,
    'notif_mode'             => fn ($v) => in_array($v, ['toast', 'systeme'], true) ? $v : null,
];

$champ  = (string) filter_input(INPUT_POST, 'champ', FILTER_DEFAULT);
$valeur = (string) filter_input(INPUT_POST, 'valeur', FILTER_DEFAULT);

if (!isset($champs[$champ])) {
    echecJson('preferences', null, 'Réglage inconnu', 400);
    exit;
}

$propre = $champs[$champ]($valeur);
if ($propre === null) {
    echecJson('preferences', null, 'Valeur invalide', 400);
    exit;
}

/*
 * En démonstration, le réglage ne vit que le temps de la session : le compte
 * est emprunté, on n'écrit rien dessus. La page reflète quand même le choix,
 * sinon l'interrupteur reviendrait tout seul à sa position.
 */
if (!estDemo()) {
    include_once "../includes/config.php";

    try {
        $pdo = Config::getConnection();
        $req = $pdo->prepare("UPDATE users SET `$champ` = :v WHERE id = :id");
        $req->execute([':v' => $propre, ':id' => (int) $_SESSION['user']['id']]);
    } catch (Throwable $e) {
        echecJson('preferences', $e, 'Réglage non enregistré');
        exit;
    }
}

// Le cache de session suit : les pages le lisent sans requête supplémentaire.
$_SESSION['user'][$champ] = $propre;

echo json_encode([
    'success' => true,
    'message' => 'Réglage enregistré',
    'valeur'  => $propre,
]);
