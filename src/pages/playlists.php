<article class="playlits-list playlist container">
    <div class="head-bar">Playlists<a href="?page=home/playlists/add_playlist" class="more-bar" data-page="home/playlists/add_playlist">+</a></div>
    <div class="body-bar">
        <?php
        include_once "../includes/config.php";
        $pdo = Config::getConnection();

        $req = $pdo->prepare("SELECT playlists.id, name, username FROM playlists LEFT JOIN users ON playlists.`created-by_id` = users.id WHERE name != 'Wait Tracks'");
        $req->execute();

        $playlists = $req->fetchAll();

        foreach ($playlists as $playlist)
        {
            $req = $pdo->prepare("SELECT COUNT(*) FROM track__playlist WHERE playlist_id = :playlist");
            $req->bindParam(":playlist", $playlist["id"]);
            $req->execute();

            $occurrence = $req->fetchColumn();

            $req = $pdo->prepare("SELECT SUM(duration) FROM tracks RIGHT JOIN track__playlist ON track_id = tracks.id WHERE playlist_id = :playlist");
            $req->bindParam(":playlist", $playlist["id"]);
            $req->execute();

            $time = $req->fetchColumn();
            ?>
            <div class="content" data-id="<?php echo $playlist['id']; ?>">
                <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                <div class="mini-content-info">
                    <div class="mini-title"><?php echo $playlist["name"]; ?></div>
                    <div style="font-size: 10px"><?php echo $playlist["username"]; ?></div>
                    <div class="mini-info"><?php if ($occurrence > 1) { echo $occurrence.' titres'; } else { echo $occurrence.' titre'; } ?> - <?= $time ?? 0 ?> min</div>
                </div>
            </div>
            <?php
        }
        ?>
    </div>
    <script src="../scripts/playlists.js"></script>
</article>