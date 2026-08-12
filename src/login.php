<?php
include_once "includes/auth.php";
demarrerSession();

$csrf = jetonCsrf();
$attente = filter_input(INPUT_GET, 'trop_de_tentatives', FILTER_VALIDATE_INT);

// Seuls la clé et le libellé sont rendus : aucun nom d'utilisateur réel
// n'apparaît dans le HTML servi.
$comptes = comptesConnexion();

/*
 * Dernier compte utilisé, mémorisé par le cookie déposé à la connexion.
 * On le valide contre la liste ci-dessus : un cookie est modifiable par le
 * client, il ne doit jamais arriver tel quel dans la page.
 */
$dernier = $_COOKIE['unison_dernier_compte'] ?? '';
$preselection = isset($comptes[$dernier]) ? $dernier : array_key_first($comptes);
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
        <?php // Les libellés sont volontairement des surnoms : la page est publique. ?>

        <div id="login-users">
            <?php foreach ($comptes as $cle => $infos): ?>
            <button type="button" class="login-user-btn<?= $cle === $preselection ? ' selected' : '' ?>"
                    onclick="selectUser(this, '<?= htmlspecialchars($cle, ENT_QUOTES) ?>')">
                <div class="login-user-avatar" style="background: <?= $infos['couleur'] ?>;"><?= mb_substr($infos['libelle'], 0, 1) ?></div>
                <div class="login-user-info">
                    <div class="name"><?= htmlspecialchars($infos['libelle'], ENT_QUOTES) ?></div>
                </div>
            </button>
            <?php endforeach; ?>
        </div>

        <div id="form-group">
            <label id="form-label">Mot de passe</label>
            <input type="password" class="form-input" name="password">
            <?php if ($attente): ?>
                <div id="password-error" style="display: flex">
                    ⏳ Trop de tentatives. Réessayez dans <?= (int) $attente ?> minute(s).
                </div>
            <?php elseif (filter_input(INPUT_GET, 'reset_password', FILTER_VALIDATE_BOOL)): ?>
                <div id="password-reset">
                    ✅ Mot de passe réinitialisé avec succès
                </div>
            <?php elseif (filter_input(INPUT_GET, 'incorrect_password', FILTER_VALIDATE_BOOL)): ?>
                <div id="password-error" style="display: flex">
                    ❌ Utilisateur ou mot de passe incorrect
                </div>
            <?php endif; ?>
        </div>

        <input type="hidden" id="selectedUser" name="selectedUser" value="<?= htmlspecialchars($preselection, ENT_QUOTES) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

        <button id="btn-login" type="submit">Se connecter</button>

        <div id="login-switch">
            Mot de passe oublié ? <a href="javascript:void(0);" onclick="forgotPassword()">Envoyer un mail</a>
        </div>
    </form>

    <!-- Accès démonstration : session en lecture seule, pour présenter le projet -->
    <form id="demo-card" method="post" action="actions/login.php">
        <input type="hidden" name="token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="demo" value="1">
        <div id="demo-separator"><span>ou</span></div>
        <button id="btn-demo" type="submit">
            <span id="demo-icon">◐</span>
            <span>
                <span class="demo-title">Découvrir la démo</span>
                <span class="demo-sub">Visite de l'interface, sans modification</span>
            </span>
        </button>
    </form>
    <script src="scripts/login.js"></script>
</body>
</html>