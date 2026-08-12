<?php
include_once "../includes/auth.php";
exigerConnexion(false);
?>
<article id="artist-bar" class="containers">
    <?php
    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("
            SELECT artists.id, artists.name, artists.img, COUNT(tracks.id) AS track_count
            FROM artists
            LEFT JOIN artist__track ON artist__track.artist_id = artists.id
            LEFT JOIN tracks ON tracks.id = artist__track.track_id
            GROUP BY artists.id, artists.name, artists.img
            ORDER BY track_count DESC, artists.name ASC
            LIMIT 6
        ");
    $req->execute();

    $listArtists = $req->fetchAll();
    $defaultArtistImg = 'https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop';
    ?>
    <div class="head-bar">Artistes<a href="?page=library/artists" class="redirect more-bar" data-page="library/artists">Voir tout</a></div>
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
        const body = document.querySelector('#artist-bar .body-bar');
        if (!body) return;

        body.addEventListener('click', (e) => {
            const card = e.target.closest('.mini-artist[data-artiste-id]');
            if (!card) return;
            sessionStorage.setItem('artiste_id', card.dataset.artisteId);
            navigateTo('library/artiste');
        });
    })();
</script>
<?php
include_once "../includes/config.php";
include_once "../includes/viewMode.php";
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
            echo '<div class="content mini-song favorite-playlist-song" data-track-id="'.$titre['id'].'" onclick="loadTrack('.$titre["id"].')">
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
        $onlyMine = isPersonalView();
        $filtreProprio = $onlyMine ? " AND playlists.`created-by_id` = :uid" : "";
        $req = $pdo->prepare("
                SELECT playlists.id, name, users.id AS user_id
                FROM playlists
                LEFT JOIN users ON playlists.`created-by_id` = users.id
                WHERE name != 'Wait Tracks'" . $filtreProprio . "
                ORDER BY name
                LIMIT 4
            ");
        if ($onlyMine) { $req->bindValue(':uid', $_SESSION['user']['id'], PDO::PARAM_INT); }
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
                    <button class="material-symbols-outlined buttons play-playlist-btn">play_arrow</button>
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

        body.addEventListener('click', async (e) => {
            const playBtn = e.target.closest('button.play-playlist-btn');
            const card = e.target.closest('.content[data-id]');

            if (!card) return;

            const id = card.dataset.id;

            // Si c'est le bouton play
            if (playBtn) {
                e.stopPropagation();
                try {
                    const res = await fetch('actions/load_playlist_to_queue.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `playlist_id=${id}`
                    });
                    const data = await res.json();

                    if (data.success && data.tracks.length > 0) {
                        // Met à jour la queue du player
                        window.waitPlaylist = data.tracks;
                        window.sourcePlaylistId = parseInt(id);
                        window.currentIndex = 0;

                        // Charge et joue la première chanson
                        loadTrack(data.tracks[0].id, true);

                        // Initialise le menu contextuel si nécessaire
                        if (window.initializeTrackContextMenus) {
                            window.initializeTrackContextMenus();
                        }

                        window.showToast('Lecture de la playlist...', 'success');
                    } else {
                        window.showToast('Playlist vide', 'error');
                    }
                } catch (err) {
                    window.showToast('Erreur chargement playlist', 'error');
                }
                return;
            }

            // Si c'est un clic normal sur la carte (pas sur un bouton)
            if (!e.target.closest('button')) {
                // Stocke l'id pour que la page playlist.php puisse le récupérer
                sessionStorage.setItem('playlist_id', id);

                // Navigue via le routeur existant
                navigateTo('library/playlist');
            }
        });
    })();
</script>
<script src="<?= assetVersionne('../scripts/dragdrop.js') ?>"></script>
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
