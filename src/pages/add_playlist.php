<?php
session_start();
$_SESSION['token'] = bin2hex(random_bytes(32));
$token = $_SESSION['token'];
session_write_close();
?>
<form class="playlit container" action="../actions/add_playlist.php" method="post">
    <h2>Création d'une playlist</h2>
    <input class="head-bar" type="text" placeholder="Nom" name="name" required>
    <div class="playlist-bouton">
        <a href="?page=home/playlists" class="more-bar bouton" data-page="home/playlists">Annuler</a>

        <input type="hidden" name="token" value="<?= $token; ?>">
        <button type="submit" class="bouton">Créer</button>
    </div>
</form>