<?php
include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("
            SELECT artists.name, COUNT(tracks.id) AS track_count
            FROM artists
            LEFT JOIN artist__track ON artist__track.artist_id = artists.id
            LEFT JOIN tracks ON tracks.id = artist__track.track_id
            GROUP BY artists.id, artists.name
            ORDER BY track_count DESC, artists.name ASC
        ");
$req->execute();

$listArtists = $req->fetchAll();
?>
<article class="artist-list">
    <div class="head-bar">Artistes</div>
    <div class="body-bar">
        <?php
        foreach ($listArtists as $artist) {
            echo '<div class="content">
                      <div><img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover"></div>
                      <div class="mini-artist">'.$artist["name"].'</div>
                  </div>';
        }
        ?>
    </div>
</article>