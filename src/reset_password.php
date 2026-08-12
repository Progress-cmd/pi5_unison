<?php
include_once "includes/auth.php";
demarrerSession();

$csrf = jetonCsrf();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? null;
$token = filter_input(INPUT_GET, 'token', FILTER_DEFAULT) ?? null;

if (!$id || !$token) {
    die('Lien invalide');
}

$token_hash = hash('sha256', $token);

include_once "includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT username FROM users WHERE id = :id AND reset_token = :token AND reset_token_expires > NOW()");
$req->bindValue(':id', $id);
$req->bindValue(':token', $token_hash);
$req->execute();

$user = $req->fetchColumn();

if (!$user) {
    die('Lien expiré ou invalide');
}

?>

<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="styles/login.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <title>Unison - Login</title>
</head>
<body>
    <form id="login-card" method="post" action="actions/reset_password.php">
        <div id="login-logo">
            <div id="logo-mark"></div>
            <span id="logo-text">Unison</span>
        </div>

        <div id="login-headline">Bienvenue,<br><em>récupérez votre compte</em></div>
        <p id="login-sub">Choisissez un nouveau mot de passe</p>

        <div id="login-users">
            <?php
            // On n'affiche que le surnom du compte concerné : cette page est
            // atteignable par lien, elle ne doit pas révéler d'identifiant.
            foreach (comptesConnexion() as $infos):
                if ($infos['username'] !== $user) continue; ?>
            <button type="button" class="login-user-btn" style="cursor: default">
                <div class="login-user-avatar" style="background: <?= $infos['couleur'] ?>;"><?= mb_substr($infos['libelle'], 0, 1) ?></div>
                <div class="login-user-info">
                    <div class="name"><?= htmlspecialchars($infos['libelle'], ENT_QUOTES) ?></div>
                </div>
            </button>
            <?php endforeach; ?>
        </div>

        <div id="form-group">
            <label id="form-label">Nouveau mot de passe</label>
            <input type="password" id="password" class="form-input" name="password"
                   minlength="10" required>

            <label id="form-label">Réécrivez-le</label>
            <input type="password" id="password-confirm" class="form-input" name="password_confirm"
                   minlength="10" required>

            <!-- Message d'erreur -->
            <div id="password-error">
                ❌ Les deux mots de passe ne correspondent pas
            </div>
        </div>

        <input type="hidden" name="token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <!-- C'est ce couple id + jeton du mail qui autorise le changement,
             pas le nom d'utilisateur : il est revérifié côté serveur. -->
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="reset_token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
        <button id="btn-login" type="submit">Réinitialiser</button>

        <div id="login-switch">
            Mot de passe retrouvé ? <a href="login.php">Se connecter</a>
        </div>
    </form>
    <script src="scripts/reset_password.js"></script>
</body>
</html>