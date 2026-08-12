<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("
            SELECT artists.id, artists.name, artists.img, COUNT(tracks.id) AS track_count
            FROM artists
            LEFT JOIN artist__track ON artist__track.artist_id = artists.id
            LEFT JOIN tracks ON tracks.id = artist__track.track_id
            GROUP BY artists.id, artists.name, artists.img
            ORDER BY track_count DESC, artists.name ASC
        ");
$req->execute();

$listArtists = $req->fetchAll();
$defaultArtistImg = 'https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop';
?>
<article id="artist-list" class="containers">
    <div class="head-bar">Artistes</div>
    <div class="body-bar">
        <?php
        foreach ($listArtists as $artist) {
            echo '<div class="mini-artist" data-artiste-id="'.$artist['id'].'">
                      <img src="'.htmlspecialchars($artist['img'] ?: $defaultArtistImg).'" class="artist-img" alt="Cover">
                      <div class="artist-name">'.htmlspecialchars($artist["name"]).'</div>
                  </div>';
        }
        ?>
    </div>
</article>

<script>
    (function() {
        const body = document.querySelector('#artist-list .body-bar');
        if (!body) return;

        body.addEventListener('click', (e) => {
            const card = e.target.closest('.mini-artist[data-artiste-id]');
            if (!card) return;
            sessionStorage.setItem('artiste_id', card.dataset.artisteId);
            navigateTo('library/artiste');
        });
    })();
</script>
