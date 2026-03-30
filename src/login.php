<?php
session_start();

$_SESSION['token'] = bin2hex(random_bytes(32));

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
    <form id="login-card" method="post" action="actions/login.php">
        <div id="login-logo">
            <div id="logo-mark"></div>
            <span id="logo-text">Unison</span>
        </div>

        <div id="login-headline">Bienvenue,<br><em>choisissez votre compte</em></div>
        <p id="login-sub">La musique, à deux — chacun son univers.</p>

        <div id="login-users">
            <button type="button" class="login-user-btn selected" onclick="selectUser(this, 'Francis')">
                <div class="login-user-avatar" style="background: #C8593A;">F</div>
                <div class="login-user-info">
                    <div class="name">Francis</div>
                    <div class="role">Tortue</div>
                </div>
            </button>
            <button type="button" class="login-user-btn" onclick="selectUser(this, 'Cassandre')">
                <div class="login-user-avatar" style="background: #4A7C99;">C</div>
                <div class="login-user-info">
                    <div class="name">Cassandre</div>
                    <div class="role">Papillon</div>
                </div>
            </button>
        </div>

        <div id="form-group">
            <label id="form-label">Mot de passe</label>
            <input type="password" class="form-input" name="password">
            <?php if (filter_input(INPUT_GET, 'reset_password', FILTER_VALIDATE_BOOL)): ?>
                <div id="password-reset">
                    ✅ Mot de passe réinitialisé avec succès
                </div>
            <?php elseif (filter_input(INPUT_GET, 'incorrect_password', FILTER_VALIDATE_BOOL)): ?>
                <div id="password-error" style="display: flex">
                    ❌ Utilisateur ou mot de passe incorrect
                </div>
            <?php endif; ?>
        </div>

        <input type="hidden" id="selectedUser" name="selectedUser" value="Francis">
        <input type="hidden" name="token" value="<?= $_SESSION['token']; ?>">

        <button id="btn-login" type="submit">Se connecter</button>

        <div id="login-switch">
            Mot de passe oublié ? <a href="javascript:void(0);" onclick="forgotPassword()">Envoyer un mail</a>
        </div>
    </form>
    <script src="scripts/login.js"></script>
</body>
</html>