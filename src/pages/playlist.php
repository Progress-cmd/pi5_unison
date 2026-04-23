<?php
include_once "../includes/config.php";

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
    SELECT tracks.id, title, duration, img,
           GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
    FROM tracks
    RIGHT JOIN track__playlist ON track_id = tracks.id
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists ON artists.id = artist__track.artist_id
    WHERE playlist_id = :id
    GROUP BY tracks.id, title, duration, img
    ORDER BY position
");
$req->bindParam(':id', $id, PDO::PARAM_INT);
$req->execute();
$tracks = $req->fetchAll(PDO::FETCH_ASSOC);
?>

<article id="playlist-content" class="containers">
    <div class="head-bar">
        <?= htmlspecialchars($playlist['name']) ?>
        <a href="#" class="more-bar" data-page="search" data-playlist-id="<?= $id ?>" data-playlist-name="<?= htmlspecialchars($playlist['name']) ?>">+</a>
    </div>
    <div class="body-bar">
        <?php foreach ($tracks as $track): ?>
            <div class="content mini-song" onclick="loadTrack(<?= $track['id'] ?>)">
                <img src="<?= htmlspecialchars($track['img']) ?>" class="song-img" alt="<?= htmlspecialchars($track['title']) ?>">
                <div class="song-infos">
                    <div class="song-title"><?= htmlspecialchars($track['title']) ?></div>
                    <div class="song-artist"><?= htmlspecialchars($track['artists_names'] ?? 'Artiste inconnu') ?></div>
                </div>
                <button class="buttons material-symbols-outlined">more_vert</button>
            </div>
        <?php endforeach; ?>
    </div>
</article>