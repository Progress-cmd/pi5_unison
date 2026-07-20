<?php
session_start();
include_once "../includes/config.php";

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    echo '<p class="error">Titre introuvable.</p>';
    exit;
}

$pdo = Config::getConnection();
$userId = $_SESSION['user']['id'] ?? 0;

// Récupère le titre
$req = $pdo->prepare("SELECT id, title, duration, img FROM tracks WHERE id = :id");
$req->execute([':id' => $id]);
$titre = $req->fetch(PDO::FETCH_ASSOC);

if (!$titre) {
    http_response_code(404);
    echo '<p class="error">Titre introuvable.</p>';
    exit;
}

// Artistes du titre
$req = $pdo->prepare("
    SELECT artists.id, artists.name
    FROM artists
    JOIN artist__track ON artist__track.artist_id = artists.id
    WHERE artist__track.track_id = :id
    ORDER BY artists.name
");
$req->execute([':id' => $id]);
$artistes = $req->fetchAll(PDO::FETCH_ASSOC);

// Genres du titre + tous les genres disponibles
$req = $pdo->prepare("
    SELECT genres.id, genres.name
    FROM genres
    JOIN track__genre ON track__genre.genre_id = genres.id
    WHERE track__genre.track_id = :id
");
$req->execute([':id' => $id]);
$currentGenres = $req->fetchAll(PDO::FETCH_ASSOC);
$currentGenreIds = array_column($currentGenres, 'id');

$req = $pdo->query("SELECT id, name FROM genres ORDER BY name");
$allGenres = $req->fetchAll(PDO::FETCH_ASSOC);

// Tags du titre + tous les tags disponibles
$req = $pdo->prepare("
    SELECT tags.id, tags.name
    FROM tags
    JOIN tag__track ON tag__track.tag_id = tags.id
    WHERE tag__track.track_id = :id
");
$req->execute([':id' => $id]);
$currentTags = $req->fetchAll(PDO::FETCH_ASSOC);
$currentTagIds = array_column($currentTags, 'id');

$req = $pdo->query("SELECT id, name FROM tags ORDER BY name");
$allTags = $req->fetchAll(PDO::FETCH_ASSOC);

// Notes du titre (filtrées selon le mode d'affichage)
include_once "../includes/viewMode.php";
$onlyMine = isPersonalView();
$filtreNote = $onlyMine ? " AND notes.`created-by_id` = :uid" : "";
$req = $pdo->prepare("
    SELECT notes.id, notes.text, notes.`created-at`, users.username
    FROM notes
    LEFT JOIN note__track ON notes.id = note__track.note_id
    LEFT JOIN users ON notes.`created-by_id` = users.id
    WHERE note__track.track_id = :id" . $filtreNote . "
    ORDER BY notes.`created-at` DESC
");
$req->bindValue(':id', $id, PDO::PARAM_INT);
if ($onlyMine) { $req->bindValue(':uid', $userId, PDO::PARAM_INT); }
$req->execute();
$notes = $req->fetchAll(PDO::FETCH_ASSOC);

// Statistiques d'écoute
$req = $pdo->prepare("SELECT nb FROM nb_listen WHERE user_id = :user_id AND track_id = :id");
$req->execute([':user_id' => $userId, ':id' => $id]);
$mesEcoutes = intval($req->fetchColumn());

$req = $pdo->prepare("SELECT COALESCE(SUM(nb), 0) FROM nb_listen WHERE track_id = :id");
$req->execute([':id' => $id]);
$totalEcoutes = intval($req->fetchColumn());

$req = $pdo->prepare("SELECT MAX(`listened-at`) FROM historical WHERE `listened-by_id` = :user_id AND track_id = :id");
$req->execute([':user_id' => $userId, ':id' => $id]);
$derniereEcoute = $req->fetchColumn();

// Playlists contenant le titre
$req = $pdo->prepare("
    SELECT playlists.id, playlists.name
    FROM playlists
    JOIN track__playlist ON track__playlist.playlist_id = playlists.id
    WHERE track__playlist.track_id = :id AND playlists.name != 'Wait Tracks'
    ORDER BY playlists.name
");
$req->execute([':id' => $id]);
$playlists = $req->fetchAll(PDO::FETCH_ASSOC);
?>

<article id="titre-detail" class="containers">
    <div class="head-bar"><?= htmlspecialchars($titre['title']) ?></div>
    <div class="body-bar">
        <div class="titre-entete">
            <img src="<?= htmlspecialchars($titre['img']) ?>" class="titre-img" alt="<?= htmlspecialchars($titre['title']) ?>">
            <div class="titre-infos">
                <div class="titre-nom"><?= htmlspecialchars($titre['title']) ?></div>
                <div class="titre-artistes">
                    <?php foreach ($artistes as $artiste): ?>
                        <span class="artiste-lien" data-artiste-id="<?= $artiste['id'] ?>"><?= htmlspecialchars($artiste['name']) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="titre-duree"><?= intdiv($titre['duration'], 60).':'.str_pad($titre['duration'] % 60, 2, '0', STR_PAD_LEFT) ?></div>
                <button type="button" class="btn-primary" onclick="loadTrack(<?= $titre['id'] ?>)">
                    <span class="material-symbols-outlined" style="vertical-align: middle;">play_arrow</span> Lire
                </button>
            </div>
        </div>

        <!-- Statistiques -->
        <h3>Statistiques</h3>
        <div class="titre-stats">
            <div class="content">
                <div class="dasboard-title"><b>Mes écoutes : </b></div>
                <div class="dashboard-value"><?= $mesEcoutes ?></div>
            </div>
            <div class="content">
                <div class="dasboard-title"><b>Écoutes totales : </b></div>
                <div class="dashboard-value"><?= $totalEcoutes ?></div>
            </div>
            <div class="content">
                <div class="dasboard-title"><b>Dernière écoute : </b></div>
                <div class="dashboard-value"><?= $derniereEcoute ? date('d/m/Y H:i', strtotime($derniereEcoute)) : 'Jamais' ?></div>
            </div>
        </div>

        <!-- Playlists contenant le titre -->
        <?php if (!empty($playlists)): ?>
            <h3>Dans les playlists</h3>
            <div class="titre-playlists">
                <?php foreach ($playlists as $playlist): ?>
                    <span class="playlist-lien tag-checkbox" data-playlist-id="<?= $playlist['id'] ?>"><?= htmlspecialchars($playlist['name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

        <!-- Genres et tags -->
        <form method="post" data-action="actions/modifier_titre.php" data-redirect="library/titre">
            <input type="hidden" name="track_id" value="<?= $titre['id'] ?>">

            <div class="form-group">
                <label>Genres</label>
                <div class="tags-selector" id="genres-selector">
                    <?php foreach ($allGenres as $genre): ?>
                        <label class="tag-checkbox">
                            <input type="checkbox" name="genres[]" value="<?= $genre['id'] ?>"
                                <?= in_array($genre['id'], $currentGenreIds) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($genre['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <input type="text" id="new-genre" placeholder="Ajouter un genre" style="margin-top: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 5px; width: 100%; box-sizing: border-box;">
                <button type="button" id="create-genre-btn" class="btn-primary" style="margin-top: 8px; width: 100%;">+ Créer le genre</button>
            </div>

            <div class="form-group">
                <label>Tags</label>
                <div class="tags-selector" id="tags-selector">
                    <?php foreach ($allTags as $tag): ?>
                        <label class="tag-checkbox">
                            <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>"
                                <?= in_array($tag['id'], $currentTagIds) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($tag['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <input type="text" id="new-tag" placeholder="Ajouter un tag" style="margin-top: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 5px; width: 100%; box-sizing: border-box;">
                <button type="button" id="create-tag-btn" class="btn-primary" style="margin-top: 8px; width: 100%;">+ Créer le tag</button>
            </div>

            <button type="submit" class="btn-primary">Sauvegarder</button>
        </form>

        <script>
            // Navigation vers les pages artiste et playlist
            document.querySelectorAll('#titre-detail .artiste-lien').forEach(el => {
                el.addEventListener('click', () => {
                    sessionStorage.setItem('artiste_id', el.dataset.artisteId);
                    navigateTo('library/artiste');
                });
            });

            document.querySelectorAll('#titre-detail .playlist-lien').forEach(el => {
                el.addEventListener('click', () => {
                    sessionStorage.setItem('playlist_id', el.dataset.playlistId);
                    navigateTo('library/playlist');
                });
            });

            // Création de genre / tag à la volée
            function brancherCreation(btnId, inputId, action, idField, selectorId, inputName) {
                document.getElementById(btnId).addEventListener('click', async (e) => {
                    e.preventDefault();
                    const nom = document.getElementById(inputId).value.trim();
                    if (!nom) {
                        alert('Entre un nom');
                        return;
                    }

                    try {
                        const res = await fetch(action, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `name=${encodeURIComponent(nom)}`
                        });
                        const data = await res.json();

                        if (data.success) {
                            const selector = document.getElementById(selectorId);
                            const label = document.createElement('label');
                            label.className = 'tag-checkbox';
                            const input = document.createElement('input');
                            input.type = 'checkbox';
                            input.name = inputName;
                            input.value = data[idField];
                            input.checked = true;
                            label.appendChild(input);
                            label.appendChild(document.createTextNode(' ' + nom));
                            selector.appendChild(label);
                            document.getElementById(inputId).value = '';
                            window.showToast(data.message);
                        } else {
                            alert('Erreur: ' + data.message);
                        }
                    } catch (e) {
                        alert('Erreur: ' + e.message);
                    }
                });
            }

            brancherCreation('create-genre-btn', 'new-genre', 'actions/creer_genre.php', 'genre_id', 'genres-selector', 'genres[]');
            brancherCreation('create-tag-btn', 'new-tag', 'actions/create_tag.php', 'tag_id', 'tags-selector', 'tags[]');

            // Suppression de notes
            document.querySelectorAll('#titre-detail .delete-note').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    if (!confirm('Supprimer cette note?')) return;

                    const noteId = btn.dataset.noteId;
                    try {
                        const res = await fetch('actions/delete_note.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `note_id=${noteId}`
                        });
                        const data = await res.json();

                        if (data.success) {
                            btn.closest('.note-item').remove();
                            window.showToast('Note supprimée');
                        } else {
                            alert('Erreur: ' + data.message);
                        }
                    } catch (e) {
                        alert('Erreur: ' + e.message);
                    }
                });
            });
        </script>

        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

        <!-- Section Notes -->
        <h3>Notes (<?= count($notes) ?>)</h3>

        <div class="notes-list">
            <?php foreach ($notes as $note): ?>
                <div class="note-item">
                    <div class="note-header">
                        <div>
                            <strong><?= htmlspecialchars($note['username'] ?? 'Anonyme') ?></strong>
                            <span class="note-date"><?= date('d/m/Y H:i', strtotime($note['created-at'])) ?></span>
                        </div>
                        <button type="button" class="delete-note" data-note-id="<?= $note['id'] ?>" style="background: none; border: none; color: #c9534f; cursor: pointer; font-size: 16px;">✕</button>
                    </div>
                    <div class="note-text"><?= nl2br(htmlspecialchars($note['text'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Ajouter une note -->
        <form method="post" data-action="actions/ajouter_note_titre.php" data-redirect="library/titre" id="add-note-form">
            <input type="hidden" name="track_id" value="<?= $titre['id'] ?>">

            <div class="form-group">
                <label>Ajouter une note</label>
                <textarea name="text" placeholder="Écris ta note ici..." rows="3" required></textarea>
            </div>

            <button type="submit" class="btn-primary">Ajouter la note</button>
        </form>
    </div>
</article>

<style>
    #titre-detail .titre-entete {
        display: flex;
        gap: 20px;
        align-items: center;
        margin-bottom: 20px;
    }

    #titre-detail .titre-img {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 8px;
    }

    #titre-detail .titre-nom {
        font-family: var(--serif);
        font-size: 22px;
        margin-bottom: 5px;
    }

    #titre-detail .titre-artistes {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 5px;
    }

    #titre-detail .artiste-lien {
        color: #C8593A;
        cursor: pointer;
        text-decoration: underline;
    }

    #titre-detail .titre-duree {
        color: #999;
        font-size: 13px;
        margin-bottom: 10px;
    }

    #titre-detail .titre-stats .content {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
    }

    #titre-detail .titre-playlists {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    #titre-detail .playlist-lien {
        cursor: pointer;
    }

    #titre-detail .form-group {
        margin-bottom: 20px;
    }

    #titre-detail label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
    }

    #titre-detail input[type="text"],
    #titre-detail textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-family: inherit;
        font-size: inherit;
        box-sizing: border-box;
    }

    #titre-detail textarea {
        resize: vertical;
    }

    #titre-detail h3 {
        margin-top: 20px;
        margin-bottom: 15px;
        font-family: var(--serif);
        font-size: 18px;
    }

    .tags-selector {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .tag-checkbox {
        display: flex;
        align-items: center;
        gap: 5px;
        cursor: pointer;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 20px;
        background: #f9f9f9;
    }

    .tag-checkbox input[type="checkbox"] {
        cursor: pointer;
    }

    .tag-checkbox:has(input:checked) {
        background-color: rgba(200, 93, 58, 0.1);
        border-color: #C8593A;
    }

    .btn-primary {
        background-color: #C8593A;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 500;
    }

    .btn-primary:hover {
        background-color: #a6483d;
    }

    .notes-list {
        margin-bottom: 30px;
    }

    .note-item {
        background: #f9f9f9;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 10px;
        border-left: 4px solid #C8593A;
    }

    .note-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        font-size: 14px;
    }

    .note-date {
        color: #999;
        font-size: 12px;
    }

    .note-text {
        color: #333;
        line-height: 1.5;
    }
</style>
