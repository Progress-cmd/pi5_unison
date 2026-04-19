<?php
include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT name FROM playlists WHERE id = :playlist");
$req->bindParam(":playlist", $playlist);
$req->execute();

$name = $req->fetchColumn();
?>
<article id="playlist-content" class="playlit container">
    <div class="head-bar"></div>
    <div class="body-bar">
    </div>
</article>

<script src="../scripts/playlist.js"></script>