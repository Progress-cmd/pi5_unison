<?php
session_start();
$_SESSION['token'] = bin2hex(random_bytes(32));
$token = $_SESSION['token'];
?>
<div id="add_playlist" class="containers">
    <div class="head-bar">
        <h2>Création d'une playlist</h2>
    </div>
    <div class="body-bar">
        <div class="content">
            <form id="add_playlist-form" data-action="../actions/add_playlist.php" data-redirect="home" method="post">
                <input class="head-bar" type="text" id="add-entry" placeholder="Nom" name="name" required>
                <div id="add_playlist-buttons">
                    <a href="?page=library/playlists" class="redirect buttons" data-page="library/playlists">Annuler</a>

                    <input type="hidden" name="token" value="<?= $token; ?>">
                    <button type="submit" class="buttons">Créer</button>
                </div>
            </form>
        </div>
    </div>
</div>