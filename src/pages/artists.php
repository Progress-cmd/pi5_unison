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
<article id="artist-list" class="containers">
    <div class="head-bar">Artistes</div>
    <div class="body-bar">
        <?php
        foreach ($listArtists as $artist) {
            echo '<div class="mini-artist">
                      <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="artist-img" alt="Cover">
                      <div class="artist-name">'.$artist["name"].'</div>
                  </div>';
        }
        ?>
    </div>
</article>