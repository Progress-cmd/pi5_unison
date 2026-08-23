<?php
include_once "../includes/auth.php";
exigerConnexion(false);
?>
<article id="propositions-bar">
    <?php
    include_once "../includes/config.php";
    include_once "../includes/viewMode.php";
include_once "../includes/rendu.php";
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
                  <img src="'.htmlspecialchars($listTrack['img'] ?? '').'" class="proposition-img" alt="'.htmlspecialchars($listTrack['title'] ?? '').' - '.htmlspecialchars($listTrack['artists_names'] ?? '').'">
                  <div class="proposition-infos">
                      <div class="title-info">'.htmlspecialchars($listTrack['title'] ?? '').'</div>
                      <div class="artist-info">'.htmlspecialchars($listTrack['artists_names'] ?? '').'</div>
                  </div>
              </button>';
    }
    ?>
</article>

<section>
    <div class="home-col">
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

            /*
             * Le LEFT JOIN depuis playlists renvoie une ligne entièrement
             * nulle quand la file est vide — la playlist existe, son contenu
             * non. Sans ce filtre, la page affichait une ligne « EN COURS »
             * sans titre, et publiait à window.waitPlaylist une piste d'id
             * null que le lecteur tentait ensuite de charger.
             */
            if (!$titres || $titres[0]['id'] === null) { $titres = []; }

            foreach ($titres as $i => $titre) {
                echo ligneTitre($titre, [
                    'classes' => $i === 0 ? 'selected' : '',
                    'badge'   => true,
                    'index'   => $i,
                ]);
            }

            if (!$titres) { echo ligneVide("File d'attente vide"); }
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

    <article id="history-bar" class="containers">
        <div class="head-bar">Historique</div>
        <div class="body-bar">
            <?php
            $req = $pdo->prepare("
                    SELECT historical.`listened-at`, tracks.id, tracks.img, tracks.title,
                           GROUP_CONCAT(DISTINCT artists.name SEPARATOR ', ') AS artists_names
                    FROM historical
                    JOIN tracks ON tracks.id = historical.track_id
                    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
                    LEFT JOIN artists ON artists.id = artist__track.artist_id
                    WHERE historical.`listened-by_id` = :user_id
                    GROUP BY historical.`listened-at`, tracks.id, tracks.title, tracks.img
                    ORDER BY historical.`listened-at` DESC
                    LIMIT 8
                ");
            $req->execute([':user_id' => $_SESSION['user']['id']]);
            $historique = $req->fetchAll(PDO::FETCH_ASSOC);

            if (!$historique) { echo ligneVide('Aucune écoute pour le moment'); }

            foreach ($historique as $ecoute) {
                echo ligneTitre($ecoute, [
                    'sous_titre' => ($ecoute['artists_names'] ?? '')
                                  . ' - ' . date('d/m H:i', strtotime($ecoute['listened-at'])),
                ]);
            }
            ?>
        </div>
    </article>
    </div>

    <div class="home-col">
    <article class="containers playlists-bar">
        <div class="head-bar">Playlists<a href="?page=library/playlists" class="redirect more-bar" data-page="library/playlists">Voir tout</a></div>
        <div class="body-bar">
            <?php
            [$conditionVisibilite, $parametres] = clausePlaylistsVisibles();
            $req = $pdo->prepare("
                    SELECT playlists.id, name, users.id AS user_id
                    FROM playlists
                    LEFT JOIN users ON playlists.`created-by_id` = users.id
                    WHERE $conditionVisibilite
                    ORDER BY name
                    LIMIT 4
                ");
            $req->execute($parametres);

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
                            <div class="playlist-info"><?= resumePlaylist((int) $occurrence, $time === null ? null : (int) $time) ?></div>
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

    <!-- Emplacement du player sur la version bureau (rempli par router.js) -->
    <div id="player-dock"></div>
    </div>

    <script>
        async function addToQueueAndPlay(trackId) {
            try {
                // La file visée est résolue côté serveur : la page n'a plus à
                // connaître son identifiant, et ne peut plus en désigner une autre.
                const res = await fetch('actions/clear_queue_and_add.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `track_id=${trackId}`
                });
                const data = await res.json();

                if (data.success) {
                    const playlistId = data.playlist_id;
                    window.waitPlaylist = data.queue;
                    window.sourcePlaylistId = null;

                    window.currentIndex = 0;

                    const queueBody = document.querySelector('#queue-bar .body-bar');
                    if (queueBody) {
                        window.remplirLignesTitres(queueBody, data.queue, {
                            file: true,
                            badge: true,
                            messageVide: "File d'attente vide",
                        });

                        // Le glisser-déposer se rebranche sur les lignes neuves.
                        queueBody.parentElement.setAttribute('data-playlist-id', playlistId);
                        window.enableDragDrop(queueBody, playlistId);
                    }

                    loadTrack(trackId);
                } else {
                    window.showToast(data.message || "Lecture impossible", 'error');
                }
            } catch (e) {
                window.showToast('Erreur réseau', 'error');
            }
        }
    </script>
</section>

<script src="<?= assetVersionne('../scripts/dragdrop.js') ?>"></script>
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
                            window.remplirLignesTitres(queueBody, data.tracks, {
                                file: true,
                                badge: true,
                            });
                        }

                        // Charge et joue la première chanson
                        loadTrack(data.tracks[0].id, true);

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
