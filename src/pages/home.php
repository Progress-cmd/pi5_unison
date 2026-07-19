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
        echo '<button class="proposition buttons" onclick="addToQueueAndPlay('.$listTrack['id'].')">
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
        <div class="head-bar">Liste d'attente</div>
        <div class="body-bar">
            <?php
            $req = $pdo->prepare("
                    SELECT tracks.id, tracks.img, tracks.title, playlists.id as playlist_id, GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
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
            $playlist_wait_id = $titres[0]['playlist_id'] ?? null;

            $select = "selected";
            $i = 0;

            foreach ($titres as $titre) {
                echo '
                <div class="content mini-song '.$select.'" data-track-id="'.$titre['id'].'" onclick="window.currentIndex = '.$i.'; loadTrack('.$titre['id'].')">
                    <img src="'.$titre['img'].'" class="song-img" alt="image">
                      <div class="song-infos">
                          <div class="song-title">'.$titre['title'].'</div>
                          <div class="song-artist">'.$titre['artists_names'].'</div>
                      </div>
                    <div class="running badge">EN COURS</div>
                        <button class="buttons material-symbols-outlined">more_vert</button>
                </div>';
                $select = "";
                $i++;
            }
            ?>
        </div>
    </article>

    <script>
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
        async function addToQueueAndPlay(trackId) {
            const playlistId = <?= json_encode($playlist_wait_id) ?>;
            
            try {
                const res = await fetch('actions/clear_queue_and_add.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `track_id=${trackId}&playlist_id=${playlistId}`
                });
                const data = await res.json();
                
                if (data.success) {
                    window.waitPlaylist = data.queue;
                    window.sourcePlaylistId = null;

                    const queueBody = document.querySelector('#queue-bar .body-bar');
                    if (queueBody) {
                        queueBody.innerHTML = '';
                        data.queue.forEach((track, idx) => {
                            const div = document.createElement('div');
                            div.className = 'content mini-song' + (idx === 0 ? ' selected' : '');
                            div.setAttribute('data-track-id', track.id);
                            div.onclick = () => { window.currentIndex = idx; loadTrack(track.id); };
                            div.innerHTML = `
                                <img src="${track.img}" class="song-img" alt="image">
                                <div class="song-infos">
                                    <div class="song-title">${track.title}</div>
                                    <div class="song-artist">${track.artists_names}</div>
                                </div>
                                <div class="running badge">EN COURS</div>
                        <button class="buttons material-symbols-outlined">more_vert</button>
                            `;
                            queueBody.appendChild(div);
                        });
                        
                        // RÉACTIVE LE DRAGDROP ET LE MENU CONTEXTUEL
                        queueBody.parentElement.setAttribute('data-playlist-id', playlistId);
                        window.enableDragDrop(queueBody, playlistId);

                        // Réinitialise les menus contextuels des chansons
                        if (window.initializeTrackContextMenus) {
                            setTimeout(() => window.initializeTrackContextMenus(), 50);
                        }
                    }

                    window.currentIndex = 0;
                    loadTrack(trackId);
                }
            } catch (e) {
                console.error('Erreur:', e);
            }
        }
    </script>
</section>

<script src="../scripts/dragdrop.js"></script>
<script>
    function initDragDrop() {
        const queueContainer = document.querySelector('#queue-bar .body-bar');
        if (queueContainer && typeof window.enableDragDrop === 'function') {
            const playlistId = <?= json_encode($playlist_wait_id) ?>;
            queueContainer.parentElement.setAttribute('data-playlist-id', playlistId);
            window.enableDragDrop(queueContainer, playlistId);
        } else if (queueContainer) {
            // Réessaie si enableDragDrop n'est pas encore disponible
            setTimeout(initDragDrop, 100);
        }
    }

    setTimeout(initDragDrop, 100);
</script>

<script>
    (function() {
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

                        // Rafraîchit l'affichage de la queue sur le home
                        const queueBody = document.querySelector('#queue-bar .body-bar');
                        if (queueBody) {
                            queueBody.innerHTML = '';
                            data.tracks.forEach((track, idx) => {
                                const div = document.createElement('div');
                                div.className = 'content mini-song' + (idx === 0 ? ' selected' : '');
                                div.setAttribute('data-track-id', track.id);
                                div.onclick = () => { window.currentIndex = idx; loadTrack(track.id); };
                                div.innerHTML = `
                                    <img src="${track.img}" class="song-img" alt="image">
                                    <div class="song-infos">
                                        <div class="song-title">${track.title}</div>
                                        <div class="song-artist">${track.artists_names}</div>
                                    </div>
                                    <div class="running badge">EN COURS</div>
                                    <button class="buttons material-symbols-outlined">more_vert</button>
                                `;
                                queueBody.appendChild(div);
                            });
                        }

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
                sessionStorage.setItem('playlist_id', id);
                navigateTo('library/playlist');
            }
        });
    })();
</script>
