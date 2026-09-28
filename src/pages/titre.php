<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
// nomPlaylist() : rendu.php n'est tiré ni par auth.php ni par config.php.
include_once "../includes/rendu.php";

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
    <?php
    /*
     * En-tête générique : le titre du morceau est déjà affiché juste en
     * dessous, en grand, à côté de sa pochette. Le répéter ici ne disait rien
     * de plus et faisait lire deux fois la même chose.
     */
    ?>
    <div class="head-bar">Titre</div>
    <div class="body-bar">
        <div class="titre-entete">
            <img src="<?= htmlspecialchars($titre['img'] ?? '') ?>" class="titre-img" alt="<?= htmlspecialchars($titre['title'] ?? '') ?>">
            <div class="titre-infos">
                <div class="titre-nom"><?= htmlspecialchars($titre['title'] ?? '') ?></div>
                <div class="titre-artistes">
                    <?php foreach ($artistes as $artiste): ?>
                        <span class="artiste-lien" data-artiste-id="<?= $artiste['id'] ?>"><?= htmlspecialchars($artiste['name'] ?? '') ?></span>
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
                <div class="dasboard-title">Mes écoutes</div>
                <div class="dashboard-value"><?= $mesEcoutes ?></div>
            </div>
            <div class="content">
                <div class="dasboard-title">Écoutes du foyer</div>
                <div class="dashboard-value"><?= $totalEcoutes ?></div>
            </div>
            <div class="content">
                <div class="dasboard-title">Ma dernière écoute</div>
                <div class="dashboard-value"><?= $derniereEcoute ? date('d/m/Y H:i', strtotime($derniereEcoute)) : 'Jamais' ?></div>
            </div>
        </div>

        <!-- Playlists contenant le titre -->
        <?php if (!empty($playlists)): ?>
            <h3>Dans les playlists</h3>
            <div class="titre-playlists">
                <?php foreach ($playlists as $playlist): ?>
                    <?php /* nomPlaylist() : « Favorite Tracks » est le nom en base, pas celui qu'on lit. */ ?>
                    <span class="playlist-lien tag-checkbox" data-playlist-id="<?= (int) $playlist['id'] ?>"><?= nomPlaylist($playlist['name'] ?? '') ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <hr class="titre-separateur">

        <!-- Genres et tags -->
        <form method="post" data-action="actions/modifier_titre.php" data-redirect="library/titre">
            <input type="hidden" name="track_id" value="<?= $titre['id'] ?>">

            <?php
            /*
             * Genres : en lecture seule, et c'est la distinction avec les
             * etiquettes.
             *
             * Un genre decrit ce qu'EST le morceau. Il arrive avec l'import,
             * depuis les metadonnees de la source (voir ytImport.php), et il
             * se propage a l'artiste — Radiohead reste rock quel que soit le
             * titre qu'on regarde. Le modifier a la main sur un titre n'a donc
             * pas de sens isolement ; cela se fait depuis l'administration,
             * ou l'on voit tous les titres concernes.
             *
             * Aucun champ « genres[] » n'est envoye : modifier_titre.php
             * distingue explicitement « non fourni » de « aucun coche », sans
             * quoi enregistrer une etiquette effacerait les genres.
             */
            ?>
            <div class="form-group">
                <label>Genres</label>
                <div class="tags-selector" id="genres-selector">
                    <?php
                    $genresDuTitre = array_values(array_filter(
                        $allGenres,
                        static fn (array $g): bool => in_array($g['id'], $currentGenreIds)
                    ));
                    ?>
                    <?php foreach ($genresDuTitre as $genre): ?>
                        <span class="tag-checkbox tag-lecture-seule">
                            <?= htmlspecialchars($genre['name'] ?? '') ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if (!$genresDuTitre): ?>
                        <span class="infos-note">Aucun genre — la source n'en indiquait pas.</span>
                    <?php endif; ?>
                </div>
                <p class="infos-note titre-note-genres">
                    Les genres viennent de l'import et suivent l'artiste. Ils se
                    modifient depuis l'administration.
                </p>
            </div>

            <div class="form-group">
                <label>Étiquettes</label>
                <p class="infos-note">Vos mots à vous : libres, et propres à ce titre.</p>
                <div class="tags-selector" id="tags-selector">
                    <?php foreach ($allTags as $tag): ?>
                        <label class="tag-checkbox">
                            <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>"
                                <?= in_array($tag['id'], $currentTagIds) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($tag['name'] ?? '') ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="titre-creation">
                    <input type="text" id="new-tag" class="titre-creation-champ" placeholder="Ajouter une étiquette">
                    <button type="button" id="create-tag-btn" class="buttons">+ Créer une étiquette</button>
                </div>
            </div>

            <button type="submit" class="buttons infos-valider">Enregistrer</button>
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
                        // Un toast, comme partout ailleurs : alert() ouvrait une
                        // fenêtre du navigateur au milieu d'une interface qui
                        // n'en utilise nulle part.
                        window.showToast('Donnez-lui un nom', 'error');
                        document.getElementById(inputId).focus();
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

                            /*
                             * Le message disait « Genre créé » et s'arrêtait là.
                             * Or le genre existe bien, mais n'est pas encore
                             * rattaché au titre : il faut enregistrer. Sans
                             * cette précision on quittait la page en croyant
                             * l'avoir fait.
                             */
                            window.showToast(data.message + " — cliquez sur « Enregistrer » pour l'appliquer", 'success', 5000);
                        } else {
                            window.showToast(data.message || 'Création impossible', 'error');
                        }
                    } catch (e) {
                        window.showToast('Erreur réseau', 'error');
                    }
                });
            }

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

        <hr class="titre-separateur">

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
                        <button type="button" class="delete-note titre-note-supprimer" data-note-id="<?= $note['id'] ?>">✕</button>
                    </div>
                    <div class="note-text"><?= nl2br(htmlspecialchars($note['text'] ?? '')) ?></div>
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


