<?php
/**
 * Création (ou promotion) du compte d'administration.
 *
 *   docker compose -f docker/docker-compose-dev.yml exec app \
 *       php /var/www/html/src/includes/creerAdmin.php
 *
 * Le mot de passe est demandé de façon interactive et n'apparaît donc ni dans
 * l'historique du shell, ni dans la liste des processus, ni dans un fichier
 * versionné. C'est aussi pourquoi le compte n'est pas écrit dans dump.sql.
 *
 * Choisir un identifiant NON DEVINABLE : la page de connexion ne propose pas ce
 * compte et exige sa saisie manuelle, le nom fait donc partie du secret.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require_once __DIR__ . '/config.php';

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

// Garde-fou : ce script ne doit jamais viser la base de démonstration.
echo "Base ciblée : " . Config::nomBase() . "\n\n";

try {
    $pdo->query("SELECT role FROM users LIMIT 1");
} catch (PDOException $e) {
    exit("La colonne « role » est absente. Appliquez d'abord :\n"
       . "  mysql_init/migrations/001_role_admin.sql\n");
}

$nom = demander("Identifiant du compte d'administration : ");
if ($nom === '') {
    exit("Abandon : identifiant vide.\n");
}

if (in_array(mb_strtolower($nom), ['admin', 'administrateur', 'root'], true)) {
    echo "\n  Attention : « $nom » est un identifiant devinable. Puisque la page de\n"
       . "  connexion n'affiche pas ce compte, son nom fait partie du secret.\n";
    if (mb_strtolower(demander("  Continuer quand même ? (oui/non) ")) !== 'oui') {
        exit("Abandon.\n");
    }
}

$mdp = demander("Mot de passe : ", true);
if (mb_strlen($mdp) < 12) {
    exit("Abandon : 12 caractères minimum pour un compte d'administration.\n");
}
if ($mdp !== demander("Confirmez le mot de passe : ", true)) {
    exit("Abandon : les deux saisies diffèrent.\n");
}

$hash = password_hash($mdp, PASSWORD_DEFAULT);

// Un compte existant est promu plutôt que dupliqué : « username » est unique.
$req = $pdo->prepare("SELECT id, role FROM users WHERE username = :nom");
$req->execute([':nom' => $nom]);
$existant = $req->fetch(PDO::FETCH_ASSOC);

if ($existant) {
    echo "\nLe compte « $nom » existe déjà (rôle actuel : {$existant['role']}).\n";
    if (mb_strtolower(demander("Le promouvoir administrateur et changer son mot de passe ? (oui/non) ")) !== 'oui') {
        exit("Abandon.\n");
    }

    $req = $pdo->prepare(
        "UPDATE users SET `password-hash` = :hash, role = 'admin',
                          reset_token = NULL, reset_token_expires = NULL
         WHERE id = :id"
    );
    $req->execute([':hash' => $hash, ':id' => $existant['id']]);
    echo "\nCompte « $nom » promu administrateur (id {$existant['id']}).\n";
} else {
    // email laissé à NULL : la colonne est unique, et la récupération par mail
    // n'a pas de sens pour un compte qui n'apparaît pas sur la page publique.
    $req = $pdo->prepare(
        "INSERT INTO users (username, email, `password-hash`, role)
         VALUES (:nom, NULL, :hash, 'admin')"
    );
    $req->execute([':nom' => $nom, ':hash' => $hash]);
    echo "\nCompte d'administration « $nom » créé (id " . $pdo->lastInsertId() . ").\n";
}

echo "Connexion : lien « Accès technique » en bas de la page de connexion.\n";
