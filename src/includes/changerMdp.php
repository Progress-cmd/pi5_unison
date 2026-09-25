<?php
/**
 * Changement du mot de passe d'un compte, en ligne de commande.
 *
 *   docker compose -f docker/docker-compose-dev.yml exec app \
 *       php /var/www/html/src/includes/changerMdp.php
 *
 * Sert quand l'autre utilisateur a perdu son mot de passe et que la
 * récupération par courriel n'est pas praticable (boîte inaccessible, SMTP
 * indisponible, compte sans adresse).
 *
 * Le mot de passe est demandé de façon interactive : il n'apparaît ni dans
 * l'historique du shell, ni dans la liste des processus, ni dans un fichier.
 * C'est la raison d'être de ce script — un `php -r 'echo password_hash(...)'`
 * laisserait le secret en clair dans les deux premiers.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require_once __DIR__ . '/config.php';
// config.php n'inclut pas le journal : sans ce require, l'appel à
// journalAttention() plus bas échouerait APRÈS l'écriture du mot de passe.
require_once __DIR__ . '/journal.php';

/** Lit une saisie, en masquant l'écho pour un mot de passe. */
function demander(string $question, bool $masque = false): string
{
    echo $question;

    if (!$masque) {
        return trim((string) fgets(STDIN));
    }

    // stty n'existe pas partout : en cas d'échec on saisit en clair plutôt que
    // de bloquer, mais on le dit.
    $ancien = @shell_exec('stty -g 2>/dev/null');
    if ($ancien) {
        @shell_exec('stty -echo');
    } else {
        echo "\n  (saisie visible : stty indisponible)\n  ";
    }

    $saisie = trim((string) fgets(STDIN));

    if ($ancien) {
        @shell_exec('stty ' . trim($ancien));
        echo "\n";
    }

    return $saisie;
}

$pdo = Config::getConnection();

// Garde-fou : comme creerAdmin.php, ce script ne doit jamais viser la démo.
echo "Base ciblée : " . Config::nomBase() . "\n\n";

// La liste rend le script utilisable sans connaître l'orthographe exacte de
// l'identifiant, et évite de créer un compte par faute de frappe.
$comptes = $pdo->query("SELECT id, username, role FROM users ORDER BY id")
               ->fetchAll(PDO::FETCH_ASSOC);

if (!$comptes) {
    exit("Aucun compte dans cette base.\n");
}

echo "Comptes existants :\n";
foreach ($comptes as $c) {
    printf("  %-4s %-20s %s\n", '#' . $c['id'], $c['username'], $c['role']);
}
echo "\n";

$cible = demander("Identifiant (ou #id) du compte à modifier : ");
if ($cible === '') {
    exit("Abandon : aucun compte indiqué.\n");
}

// On accepte « #3 », « 3 » ou le nom : c'est la liste ci-dessus qu'on recopie.
$parId = ltrim($cible, '#');
if (ctype_digit($parId)) {
    $req = $pdo->prepare("SELECT id, username, role FROM users WHERE id = :v");
    $req->execute([':v' => (int) $parId]);
} else {
    $req = $pdo->prepare("SELECT id, username, role FROM users WHERE username = :v");
    $req->execute([':v' => $cible]);
}
$compte = $req->fetch(PDO::FETCH_ASSOC);

if (!$compte) {
    exit("Abandon : aucun compte « $cible ».\n");
}

echo "\nCompte visé : « {$compte['username']} » (#{$compte['id']}, {$compte['role']}).\n";
if (mb_strtolower(demander("Changer son mot de passe ? (oui/non) ")) !== 'oui') {
    exit("Abandon.\n");
}

$mdp = demander("Nouveau mot de passe : ", true);

/*
 * Le seuil suit celui de l'application : 10 caractères pour un compte ordinaire
 * (changer_mdp.php, reset_password.php), 12 pour l'administration
 * (creerAdmin.php). Un script qui serait plus permissif déciderait en pratique
 * pour tous les autres chemins.
 */
$minimum = $compte['role'] === 'admin' ? 12 : 10;
if (mb_strlen($mdp) < $minimum) {
    exit("Abandon : $minimum caractères minimum pour ce compte.\n");
}
if ($mdp !== demander("Confirmez le mot de passe : ", true)) {
    exit("Abandon : les deux saisies diffèrent.\n");
}

/*
 * Le jeton de session est régénéré, comme dans changer_mdp.php : un mot de passe
 * changé doit fermer les sessions restées ouvertes ailleurs, sinon le changement
 * ne protège de rien. Les jetons de réinitialisation en cours sont annulés pour
 * la même raison (reinit_mdp.php) : un lien reçu par courriel avant ce
 * changement ne doit plus permettre de reprendre la main.
 */
$req = $pdo->prepare(
    "UPDATE users
        SET `password-hash` = :hash,
            jeton_session = :jeton,
            reset_token = NULL,
            reset_token_expires = NULL
      WHERE id = :id"
);
$req->execute([
    ':hash'  => password_hash($mdp, PASSWORD_DEFAULT),
    ':jeton' => bin2hex(random_bytes(32)),
    ':id'    => $compte['id'],
]);

// On journalise le fait, jamais le secret.
journalAttention('auth', 'mot_de_passe_change_cli',
    'Mot de passe changé en ligne de commande pour « ' . $compte['username'] . ' »',
    ['compte_id' => (int) $compte['id'], 'compte' => $compte['username'],
     'role' => $compte['role']]);

echo "\nMot de passe changé pour « {$compte['username']} ».\n";
echo "Les sessions ouvertes sur d'autres appareils ont été fermées.\n";
