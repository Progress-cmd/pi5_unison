<article id="propositions-bar">
    <?php
    session_start();

    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("
            SELECT tracks.id, tracks.img, tracks.title,
                   GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
            FROM tracks
            LEFT JOIN artist__track ON artist__track.track_id = tracks.id
            LEFT JOIN artists ON artists.id = artist__track.artist_id
            GROUP BY tracks.id
            ORDER BY RAND()
            LIMIT 8
        ");
    $req->execute();
    $listTracks = $req->fetchAll(PDO::FETCH_ASSOC);

    foreach ($listTracks as $listTrack)
    {
        echo '<button class="proposition buttons" onclick="loadTrack('.$listTrack['id'].')">
                  <img src="'.htmlspecialchars($listTrack['img']).'" class="proposition-img" alt="'.htmlspecialchars($listTrack['title']).' - '.htmlspecialchars($listTrack['artists_names']).'">
                  <div class="proposition-infos">
                      <div class="title-info">'.htmlspecialchars($listTrack['title']).'</div>
                      <div class="artist-info">'.htmlspecialchars($listTrack['artists_names']).'</div>
                  </div>
              </button>';
    }
    ?>
</article>

<section>
    <article id="queue-bar" class="containers">
        <div class="head-bar">Liste d'attente<a href="?page=home/queue" class="redirect more-bar" data-page="home/queue">Modifier</a></div>
        <div class="body-bar">
            <?php
            $req = $pdo->prepare("
                    SELECT tracks.id, tracks.img, tracks.title, GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
                    FROM playlists
                    LEFT JOIN track__playlist ON playlist_id = playlists.id
                    LEFT JOIN tracks ON track_id = tracks.id
                    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
                    LEFT JOIN artists ON artists.id = artist__track.artist_id
                    WHERE playlists.name = 'Wait Tracks' AND playlists.`created-by_id` = :user_id
                    GROUP BY tracks.id, tracks.img, tracks.title, track__playlist.position
                    ORDER BY track__playlist.position
                ");
            $req->execute([':user_id' => $_SESSION['user']['id']]);

            $titres = $req->fetchAll();

            $select = "selected";

            foreach ($titres as $titre) {
                echo '
                <div class="content mini-song '.$select.'" onclick="loadTrack('.$titre['id'].')">
                    <img src="'.$titre['img'].'" class="song-img" alt="image">
                      <div class="song-infos">
                          <div class="song-title">'.$titre['title'].'</div>
                          <div class="song-artist">'.$titre['artists_names'].'</div>
                      </div>
                    <div class="running badge">EN COURS</div>
                    <button class="buttons material-symbols-outlined">more_vert</button>
                </div>';
                $select = "";
            }
            ?>
        </div>
    </article>
    <script>
        // Notifie le player que la playlist est prête
        window.waitPlaylist = <?= json_encode($titres) ?>;
        window.dispatchEvent(new CustomEvent('playlistReady', {
            detail: { playlist: window.waitPlaylist }
        }));
        window.currentIndex = 0;
    </script>

    <article class="containers playlists-bar">
        <div class="head-bar">Playlists<a href="?page=library/playlists" class="redirect more-bar" data-page="library/playlists">Voir tout</a></div>
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
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="playlist-img" alt="Cover">
                    <div class="playlist-infos">
                        <div class="playlist-title"><?php echo $playlist["name"]; ?></div>
                        <div class="playlist-info"><?php if ($occurrence > 1) { echo $occurrence.' titres'; } else { echo $occurrence.' titre'; } ?> - <?php echo intdiv($time ?? 0, 60).':'.$time%60; ?> min</div>
                    </div>
                    <button class="material-symbols-outlined buttons">play_arrow</button>
                    <button class="buttons material-symbols-outlined">more_vert</button>
                </div>
                <?php
            }
            ?>
        </div>
    </article>
</section>

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