<?php
session_start();

$_SESSION['token'] = bin2hex(random_bytes(32));

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? null;
$token = filter_input(INPUT_GET, 'token', FILTER_DEFAULT) ?? null;

if (!$id || !$token) {
    die('Lien invalide');
}

$token_hash = hash('sha256', $token);

include_once "includes/config.php";
$pdo = new PDO("mysql:host=".config::$HOST.";dbname=".Config::$NAME, Config::$USER, Config::$PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

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
            <?php if ($user === "Francis"): ?>
            <button type="button" class="login-user-btn" style="cursor: default">
                <div class="login-user-avatar" style="background: #C8593A;">F</div>
                <div class="login-user-info">
                    <div class="name">Francis</div>
                    <div class="role">Tortue</div>
                </div>
            </button>
            <?php elseif ($user === "Cassandre"): ?>
            <button type="button" class="login-user-btn" style="cursor: default">
                <div class="login-user-avatar" style="background: #4A7C99;">C</div>
                <div class="login-user-info">
                    <div class="name">Cassandre</div>
                    <div class="role">Papillon</div>
                </div>
            </button>
            <?php endif; ?>
        </div>

        <div id="form-group">
            <label id="form-label">Nouveau mot de passe</label>
            <input type="password" id="password" class="form-input" name="password" required>

            <label id="form-label">Réécrivez-le</label>
            <input type="password" id="password-confirm" class="form-input" name="password_confirm" required>

            <!-- Message d'erreur -->
            <div id="password-error">
                ❌ Les deux mots de passe ne correspondent pas
            </div>
        </div>

        <input type="hidden" name="token" value="<?= $_SESSION['token']; ?>">
        <input type="hidden" name="user" value="<?= $user; ?>">
        <button id="btn-login" type="submit">Réinitialiser</button>

        <div id="login-switch">
            Mot de passe retrouvé ? <a href="login.php">Se connecter</a>
        </div>
    </form>
    <script src="scripts/reset_password.js"></script>
</body>
</html>