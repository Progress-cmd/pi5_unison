<?php
include_once "../includes/auth.php";
exigerConnexion(false);
?>
<article id="playlists-list" class="containers">
    <div class="head-bar">Playlists<a href="?page=library/playlists/add_playlist" class="more-bar" data-page="library/playlists/add_playlist">Créer</a></div>
    <div class="body-bar">
        <?php
        include_once "../includes/config.php";
include_once "../includes/rendu.php";
        include_once "../includes/viewMode.php";
        $pdo = Config::getConnection();

        // File d'attente masquée, favoris limités au compte courant, et en
        // mode perso les playlists des autres comptes en plus.
        [$ouVisibles, $parametres] = clausePlaylistsVisibles(true);

        $req = $pdo->prepare("
                SELECT playlists.id, name, users.id AS user_id
                FROM playlists
                LEFT JOIN users ON playlists.`created-by_id` = users.id
                WHERE $ouVisibles
                ORDER BY name
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
        const body = document.getElementById('playlists-list');
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