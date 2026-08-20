<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
include_once "../includes/rendu.php";
include_once "../includes/viewMode.php";

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    echo '<p class="error">Playlist introuvable.</p>';
    exit;
}

$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT name, username FROM playlists LEFT JOIN users ON playlists.`created-by_id` = users.id WHERE playlists.id = :id");
$req->bindParam(':id', $id, PDO::PARAM_INT);
$req->execute();
$playlist = $req->fetch(PDO::FETCH_ASSOC);

if (!$playlist) {
    http_response_code(404);
    echo '<p class="error">Playlist introuvable.</p>';
    exit;
}

$req = $pdo->prepare("
    SELECT tracks.id, title, duration, tracks.img,
           GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
    FROM tracks
    RIGHT JOIN track__playlist ON track_id = tracks.id
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists ON artists.id = artist__track.artist_id
    WHERE playlist_id = :id
    GROUP BY tracks.id, title, duration, tracks.img
    ORDER BY position
");
$req->bindParam(':id', $id, PDO::PARAM_INT);
$req->execute();
$tracks = $req->fetchAll(PDO::FETCH_ASSOC);

// Récupère les tags
$req = $pdo->prepare("
    SELECT tags.id, tags.name
    FROM tags
    LEFT JOIN tag__playlist ON tags.id = tag__playlist.tag_id
    WHERE tag__playlist.playlist_id = :id
");
$req->execute([':id' => $id]);
$tags = $req->fetchAll(PDO::FETCH_ASSOC);

// Récupère les notes (filtrées selon le mode d'affichage)
$onlyMine = isPersonalView();
$filtreNote = $onlyMine ? " AND notes.`created-by_id` = :uid" : "";
$req = $pdo->prepare("
    SELECT notes.id, notes.text, notes.`created-at`, users.username
    FROM notes
    LEFT JOIN note__playlist ON notes.id = note__playlist.note_id
    LEFT JOIN users ON notes.`created-by_id` = users.id
    WHERE note__playlist.playlist_id = :id" . $filtreNote . "
    ORDER BY notes.`created-at` DESC
");
$req->bindValue(':id', $id, PDO::PARAM_INT);
if ($onlyMine) { $req->bindValue(':uid', $_SESSION['user']['id'] ?? 0, PDO::PARAM_INT); }
$req->execute();
$notes = $req->fetchAll(PDO::FETCH_ASSOC);
?>

<article id="playlist-content" class="containers">
    <div class="head-bar">
        <?= htmlspecialchars($playlist['name'] ?? '') ?>
        <div style="display: flex; gap: 10px;">
            <a href="#" class="more-bar" data-page="search" data-playlist-id="<?= $id ?>" data-playlist-name="<?= htmlspecialchars($playlist['name'] ?? '') ?>">+</a>
            <button class="buttons material-symbols-outlined edit-playlist-inline" data-playlist-id="<?= $id ?>" style="background: none; border: none; cursor: pointer; color: inherit; font-size: inherit;">more_vert</button>
        </div>
    </div>
    <div class="body-bar">
        <!-- Tags -->
        <?php if (!empty($tags)): ?>
            <div style="margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 8px;">
                <?php foreach ($tags as $tag): ?>
                    <span style="background: #f0f0f0; padding: 6px 12px; border-radius: 20px; font-size: 12px; color: #666;">
                        <?= htmlspecialchars($tag['name'] ?? '') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if (!empty($notes)): ?>
            <div style="margin-bottom: 15px; background: #f9f9f9; padding: 12px; border-radius: 8px; border-left: 3px solid #C8593A;">
                <strong>Notes:</strong>
                <?php foreach ($notes as $note): ?>
                    <div style="margin-top: 8px; padding: 8px; background: white; border-radius: 4px; font-size: 12px;">
                        <strong><?= htmlspecialchars($note['username'] ?? 'Anonyme') ?></strong>
                        <span style="color: #999; font-size: 11px;"><?= date('d/m/Y', strtotime($note['created-at'])) ?></span>
                        <p style="margin: 4px 0; color: #333;"><?= nl2br(htmlspecialchars($note['text'] ?? '')) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Chansons -->
        <?php foreach ($tracks as $track): ?>
            <?= ligneTitre($track, ['sous_titre' => $track['artists_names'] ?: 'Artiste inconnu']) ?>
        <?php endforeach; ?>
        <?php if (!$tracks): ?><?= ligneVide('Cette playlist est vide') ?><?php endif; ?>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/dragdrop.js') ?>"></script>
<script>
    const playlistId = <?= $id ?>;
    setTimeout(() => {
        const container = document.querySelector("#playlist-content .body-bar");
        if (container) {
            container.parentElement.setAttribute("data-playlist-id", playlistId);
            enableDragDrop(container, playlistId);
        }

        // Réaffiche le bouton favorite du player
        document.querySelectorAll("#extend .favorite-button, #retract .favorite-button").forEach(btn => {
            btn.style.display = '';
        });

        // Marque la playlist si c'est Favorite Tracks pour modifier le menu contextuel
        const playlistName = "<?= htmlspecialchars($playlist['name'] ?? '') ?>";
        if (playlistName === "Favorite Tracks") {
            // Marque les chansons pour modifier le menu contextuel
            document.querySelectorAll("#playlist-content .mini-song").forEach(song => {
                song.classList.add("favorite-playlist-song");
            });
        }

        if (playlistName !== "Favorite Tracks") {
            // Charge la première chanson et met à jour l'état du bouton favorite
            try {
                const firstTrack = document.querySelector("#playlist-content .mini-song");
                if (firstTrack) {
                    const trackId = firstTrack.dataset.trackId;
                    if (trackId && typeof window.getFavorite === 'function') {
                        setTimeout(() => window.getFavorite(trackId), 200);
                    }
                }
            } catch (e) {
                console.error('Erreur getFavorite:', e);
            }
        }
    }, 100);
</script>