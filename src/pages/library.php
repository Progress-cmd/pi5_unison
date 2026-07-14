<article id="artist-bar" class="containers">
    <?php
    session_start();

    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("
            SELECT artists.name, COUNT(tracks.id) AS track_count
            FROM artists
            LEFT JOIN artist__track ON artist__track.artist_id = artists.id
            LEFT JOIN tracks ON tracks.id = artist__track.track_id
            GROUP BY artists.id, artists.name
            ORDER BY track_count DESC, artists.name ASC
            LIMIT 4
        ");
    $req->execute();

    $listArtists = $req->fetchAll();
    ?>
    <div class="head-bar">Artistes<a href="?page=library/artists" class="redirect more-bar" data-page="library/artists">Voir tout</a></div>
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
<?php
include_once "../includes/config.php";
$pdo = Config::getConnection();
?>

<article id="favorite-bar" class="containers">
    <div class="head-bar">Favorite Tracks</div>
    <div class="body-bar">
        <?php
        $req = $pdo->prepare("
                SELECT tracks.id, tracks.img, tracks.title, playlists.id as playlist_id, GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
                FROM playlists
                LEFT JOIN track__playlist ON playlist_id = playlists.id
                LEFT JOIN tracks ON track_id = tracks.id
                LEFT JOIN artist__track ON artist__track.track_id = tracks.id
                LEFT JOIN artists ON artists.id = artist__track.artist_id
                WHERE playlists.name = 'Favorite Tracks' AND playlists.`created-by_id` = :user_id
                GROUP BY tracks.id, tracks.img, tracks.title, track__playlist.position
                ORDER BY track__playlist.position
            ");
        $req->execute([':user_id' => $_SESSION['user']['id']]);

        $titres = $req->fetchAll();
        if ($titres[0]["id"] === NULL) { $titres = []; }
        $playlist_favorite_id = $titres[0]['playlist_id'] ?? null;

        foreach ($titres as $titre) {
            echo '<div class="content mini-song" data-track-id="'.$titre['id'].'" onclick="loadTrack('.$titre["id"].')">
                      <img src="'.$titre["img"].'" class="song-img" alt=" ">
                      <div class="song-infos">
                          <div class="song-title">'.$titre["title"].'</div>
                          <div class="song-artist">'.$titre["artists_names"].'</div>
                      </div>
                      <button class="buttons material-symbols-outlined">more_vert</button>
                  </div>';
        }
        ?>
    </div>
</article>
    </div>
</article>

<article class="containers playlists-bar">
    <div class="head-bar">Playlists<a href="?page=library/playlists" class="more-bar" data-page="library/playlists">Voir tout</a></div>
    <div class="body-bar">
        <?php
        $req = $pdo->prepare("
                SELECT playlists.id, name, users.id AS user_id
                FROM playlists
                LEFT JOIN users ON playlists.`created-by_id` = users.id
                WHERE name != 'Wait Tracks'
                ORDER BY name
                LIMIT 4
            ");
        $req->execute();

        $playlists = $req->fetchAll();

        foreach ($playlists as $playlist)
        {
            $req = $pdo->prepare("SELECT COUNT(*) FROM track__playlist WHERE playlist_id = :playlist");
            $req->execute([':playlist' => $playlist['id']]);

            $occurrence = $req->fetchColumn();

            $req = $pdo->prepare("SELECT SUM(duration) FROM tracks RIGHT JOIN track__playlist ON track_id = tracks.id WHERE playlist_id = :playlist");
            $req->execute([':playlist' => $playlist['id']]);

            $time = $req->fetchColumn();
            ?>
            <div class="content playlist-<?= $playlist['user_id'] ?> mini-playlist" data-id="<?php echo $playlist['id']; ?>">
                <div>
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="playlist-img" alt="Cover">
                    <div class="playlist-infos">
                        <div class="playlist-title"><?php echo $playlist["name"]; ?></div>
                        <div class="playlist-info"><?php if ($occurrence > 1) { echo $occurrence.' titres'; } else { echo $occurrence.' titre'; } ?> - <?php echo intdiv($time ?? 0, 60).':'.$time%60; ?> min</div>
                    </div>
                </div>
                <div class="playlist-controls">
                    <button class="material-symbols-outlined buttons">play_arrow</button>
                    <button class="buttons material-symbols-outlined">more_vert</button>
                </div>
            </div>
            <?php
        }
        ?>
    </div>
</article>

<script>
    (function() {
        // Délègue le clic sur tous les .content qui ont un data-id
        const body = document.querySelector('.playlists-bar');
        if (!body) return;

        body.addEventListener('click', (e) => {
            const card = e.target.closest('.content[data-id]');
            if (!card) return;

            const id = card.dataset.id;

            // Stocke l'id pour que la page playlist.php puisse le récupérer
            sessionStorage.setItem('playlist_id', id);

            // Navigue via le routeur existant
            navigateTo('library/playlist');
        });
    })();
</script>
<script src="../scripts/dragdrop.js"></script>
<script>
    setTimeout(() => {
        const favoriteContainer = document.querySelector('#favorite-bar .body-bar');
        if (favoriteContainer) {
            const playlistId = <?= json_encode($playlist_favorite_id ?? null) ?>;
            if (playlistId) {
                favoriteContainer.parentElement.setAttribute('data-playlist-id', playlistId);
                window.enableDragDrop(favoriteContainer, playlistId);
            }
        }
    }, 100);
</script>
