<?php
session_start();
include_once "../includes/config.php";

$playlistId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$playlistId) {
    echo '<p class="error">Playlist introuvable.</p>';
    exit;
}

$pdo = Config::getConnection();

// Récupère la playlist
$req = $pdo->prepare("SELECT id, name FROM playlists WHERE id = :id AND `created-by_id` = :user_id");
$req->execute([':id' => $playlistId, ':user_id' => $_SESSION['user']['id']]);
$playlist = $req->fetch(PDO::FETCH_ASSOC);

if (!$playlist) {
    http_response_code(404);
    echo '<p class="error">Playlist introuvable.</p>';
    exit;
}

// Vérifie que c'est pas une playlist système
if (in_array($playlist['name'], ['Wait Tracks', 'Favorite Tracks'])) {
    http_response_code(403);
    echo '<p class="error">Impossible de modifier les playlists système.</p>';
    exit;
}

// Récupère les tags associés
$req = $pdo->prepare("
    SELECT tags.id, tags.name
    FROM tags
    LEFT JOIN tag__playlist ON tags.id = tag__playlist.tag_id
    WHERE tag__playlist.playlist_id = :playlist_id
");
$req->execute([':playlist_id' => $playlistId]);
$currentTags = $req->fetchAll(PDO::FETCH_ASSOC);
$currentTagIds = array_column($currentTags, 'id');

// Récupère tous les tags disponibles
$req = $pdo->query("SELECT id, name FROM tags ORDER BY name");
$allTags = $req->fetchAll(PDO::FETCH_ASSOC);

// Récupère les notes
$req = $pdo->prepare("
    SELECT notes.id, notes.text, notes.`created-at`, users.username
    FROM notes
    LEFT JOIN note__playlist ON notes.id = note__playlist.note_id
    LEFT JOIN users ON notes.`created-by_id` = users.id
    WHERE note__playlist.playlist_id = :playlist_id
    ORDER BY notes.`created-at` DESC
");
$req->execute([':playlist_id' => $playlistId]);
$notes = $req->fetchAll(PDO::FETCH_ASSOC);
?>

<article id="edit-playlist" class="containers">
    <div class="head-bar">Éditer: <?= htmlspecialchars($playlist['name']) ?></div>

    <div class="body-bar">
        <!-- Formulaire de modification -->
        <form method="post" data-action="actions/edit_playlist.php" data-redirect="library/playlists">
            <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">

            <div class="form-group">
                <label>Nom de la playlist</label>
                <input type="text" name="name" value="<?= htmlspecialchars($playlist['name']) ?>" required>
            </div>

            <div class="form-group">
                <label>Tags</label>
                <div class="tags-selector">
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
            document.getElementById('create-tag-btn').addEventListener('click', async (e) => {
                e.preventDefault();
                const tagName = document.getElementById('new-tag').value.trim();
                if (!tagName) {
                    alert('Entre un nom de tag');
                    return;
                }

                try {
                    const res = await fetch('actions/create_tag.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `name=${encodeURIComponent(tagName)}`
                    });
                    const data = await res.json();

                    if (data.success) {
                        // Ajoute le tag à la liste
                        const tagsDiv = document.querySelector('.tags-selector');
                        const label = document.createElement('label');
                        label.className = 'tag-checkbox';
                        label.innerHTML = `
                            <input type="checkbox" name="tags[]" value="${data.tag_id}" checked>
                            ${tagName}
                        `;
                        tagsDiv.appendChild(label);
                        document.getElementById('new-tag').value = '';
                        window.showToast('Tag créé!');
                    } else {
                        alert('Erreur: ' + data.message);
                    }
                } catch (e) {
                    alert('Erreur: ' + e.message);
                }
            });

            // Suppression de notes
            document.querySelectorAll('.delete-note').forEach(btn => {
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
                            <strong><?= htmlspecialchars($note['username']) ?></strong>
                            <span class="note-date"><?= date('d/m/Y H:i', strtotime($note['created-at'])) ?></span>
                        </div>
                        <button type="button" class="delete-note" data-note-id="<?= $note['id'] ?>" style="background: none; border: none; color: #c9534f; cursor: pointer; font-size: 16px;">✕</button>
                    </div>
                    <div class="note-text"><?= nl2br(htmlspecialchars($note['text'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Ajouter une note -->
        <form method="post" data-action="actions/add_note_to_playlist.php" data-redirect="library/edit-playlist" id="add-note-form">
            <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">

            <div class="form-group">
                <label>Ajouter une note</label>
                <textarea name="text" placeholder="Écris ta note ici..." rows="3" required></textarea>
            </div>

            <button type="submit" class="btn-primary">Ajouter la note</button>
        </form>
    </div>
</article>

<style>
    #edit-playlist .form-group {
        margin-bottom: 20px;
    }

    #edit-playlist label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
    }

    #edit-playlist input[type="text"],
    #edit-playlist textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-family: inherit;
        font-size: inherit;
        box-sizing: border-box;
    }

    #edit-playlist textarea {
        resize: vertical;
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

    #edit-playlist h3 {
        margin-top: 30px;
        margin-bottom: 15px;
        font-family: var(--serif);
        font-size: 18px;
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

    hr {
        border: none;
        border-top: 1px solid #ddd;
    }
</style>
