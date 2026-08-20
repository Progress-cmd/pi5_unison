<?php
include_once "../includes/auth.php";
exigerConnexion(false);
$lien = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);

if ($lien === null || $lien === false) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
    $token = $_SESSION['token'];
    ?>
    <form data-page="import" id="import-form" class="containers" method="post">
        <button class="material-symbols-outlined" type="submit">manage_search</button>
        <input type="url" name="url" placeholder="Lien Youtube" id="import-entry" required>

        <input type="hidden" name="token" value="<?= $token; ?>">
    </form>

    <article class="containers">
        <div class="body-bar">
            <div class="content">
                Importez un titre pour l'ajouter et ajuster ses informations,
                ou collez plusieurs liens ci-dessous pour un import en masse.
            </div>
        </div>
    </article>

    <!-- Import multiple : plusieurs liens ou une playlist YouTube -->
    <article class="containers" id="import-multiple">
        <div class="head-bar">Import multiple</div>
        <div class="body-bar">
            <textarea id="bulk-urls" placeholder="Collez un lien YouTube par ligne&#10;ou un lien de playlist à importer en entier..."></textarea>
            <div id="bulk-actions">
                <span id="bulk-hint">Playlists développées automatiquement</span>
                <button type="button" id="bulk-import-btn" class="buttons">Importer tout</button>
            </div>
            <div id="bulk-progress"></div>
        </div>
    </article>

    <script>
    // Connecteur vers le module global window.BulkImport (scripts/bulk-import.js).
    // L'orchestration vit hors de la page : l'import continue même si l'on
    // navigue ailleurs, et l'état est ré-affiché quand on revient ici.
    (function () {
        const btn = document.getElementById('bulk-import-btn');
        const textarea = document.getElementById('bulk-urls');
        const progress = document.getElementById('bulk-progress');
        if (!btn || !textarea || !window.BulkImport) return;

        function render(state) {
            progress.innerHTML = '';

            const echecs = state.items.filter(i => i.status === 'error');

            // Bilan des échecs en tête de liste : c'est ce qu'on doit voir en
            // premier en revenant sur la page, pas ce qu'on doit aller chercher
            // au milieu de cinquante lignes vertes.
            if (echecs.length && !state.running) {
                const bilan = document.createElement('div');
                bilan.id = 'bulk-echecs';
                bilan.innerHTML = `
                    <div class="bulk-echecs-tete">
                        <span class="material-symbols-outlined">error</span>
                        <b>${echecs.length} import(s) en échec</b>
                        <button type="button" id="copier-echecs" class="buttons">Copier les liens</button>
                    </div>`;

                echecs.forEach(it => {
                    const ligne = document.createElement('div');
                    ligne.className = 'bulk-echec-ligne';
                    ligne.innerHTML = '<div class="bulk-echec-titre"></div><div class="bulk-echec-raison"></div>';
                    ligne.querySelector('.bulk-echec-titre').textContent = it.title;
                    ligne.querySelector('.bulk-echec-raison').textContent = it.raison || 'Raison inconnue';
                    bilan.appendChild(ligne);
                });

                bilan.querySelector('#copier-echecs').addEventListener('click', () => {
                    const liens = echecs.map(i => i.url).filter(Boolean).join('\n');
                    navigator.clipboard.writeText(liens)
                        .then(() => window.showToast('Liens copiés', 'success', 3000))
                        .catch(() => window.showToast('Copie impossible', 'error'));
                });

                progress.appendChild(bilan);
            }

            state.items.forEach(it => {
                const div = document.createElement('div');
                div.className = 'bulk-item bulk-' + it.status;
                div.innerHTML = '<span class="bulk-status material-symbols-outlined"></span>'
                              + '<span class="bulk-textes"><span class="bulk-label"></span>'
                              + '<span class="bulk-raison"></span></span>';
                div.querySelector('.bulk-label').textContent = it.title;
                if (it.status === 'error') {
                    div.querySelector('.bulk-raison').textContent = it.raison || 'Raison inconnue';
                }
                progress.appendChild(div);
            });

            const traites = state.items.filter(i => i.status === 'done' || i.status === 'error').length;
            btn.disabled = state.running;
            btn.textContent = state.running
                ? (state.items.length ? `Import ${traites}/${state.items.length}` : 'Analyse...')
                : 'Importer tout';
        }

        // Ré-affiche l'état courant si un import tourne déjà (retour sur la page)
        render(window.BulkImport.state);

        // Un seul écouteur, même si la page est réinjectée plusieurs fois
        if (window._bulkPageHandler) {
            window.removeEventListener('bulkimport:update', window._bulkPageHandler);
        }
        window._bulkPageHandler = (e) => render(e.detail);
        window.addEventListener('bulkimport:update', window._bulkPageHandler);

        btn.addEventListener('click', () => window.BulkImport.start(textarea.value));
    })();
    </script>
<?php } else {
    if (
            !isset($_POST['token'], $_SESSION['token']) ||
            $_POST['token'] !== $_SESSION['token']
    ) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Token invalide']);
        exit;
    }

    unset($_SESSION['token']);
    $_SESSION['token'] = bin2hex(random_bytes(32));
    $token = $_SESSION['token'];

    $cmd = "yt-dlp --skip-download --no-playlist --dump-json ".escapeshellarg($lien);
    $lien = null;

    $json = shell_exec($cmd);
    if (is_null($json)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Lien invalide, aucune musique trouvée"]);
        exit;
    }
    $data = json_decode($json, true);

    // yt-dlp ne renseigne 'track'/'artist' que pour de rares vidéos disposant
    // d'un encart Content ID ; pour l'immense majorité des imports (y compris
    // depuis music.youtube.com), ces champs sont vides. On retombe alors sur
    // la convention de titre "Artiste - Titre" puis sur le nom de la chaîne.
    $trackTitle  = $data['track'] ?? null;
    $trackArtist = $data['artist'] ?? null;
    if (!$trackArtist && !empty($data['artists']) && is_array($data['artists'])) {
        $trackArtist = implode(', ', $data['artists']);
    }
    if (!$trackArtist && !empty($data['creators']) && is_array($data['creators'])) {
        $trackArtist = implode(', ', $data['creators']);
    }

    if (!$trackTitle || !$trackArtist) {
        $videoTitle = $data['fulltitle'] ?? $data['title'] ?? '';
        // Retire les mentions parasites du type "(Official Video)", "[Lyrics]", "(4K Remaster)"
        $cleanTitle = preg_replace(
            '/\s*[\(\[][^\)\]]*(official|lyric|audio|video|visualizer|mv|remaster|hd|4k)[^\)\]]*[\)\]]\s*/i',
            ' ',
            $videoTitle
        );
        $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));

        if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/', $cleanTitle, $m)) {
            if (!$trackArtist) { $trackArtist = trim($m[1]); }
            if (!$trackTitle)  { $trackTitle  = trim($m[2]); }
        } elseif (!$trackTitle) {
            $trackTitle = $cleanTitle;
        }
    }

    if (!$trackArtist) {
        $channelName = $data['channel'] ?? $data['uploader'] ?? '';
        $trackArtist = preg_replace('/\s*-\s*Topic$/i', '', $channelName) ?: null;
    }

    /*
     * Valeurs gardées telles quelles.
     *
     * Elles étaient échappées ici, en amont — donc renvoyées échappées dans
     * les champs du formulaire, repostées échappées, et finalement écrites
     * ainsi en base : « Rock & Roll » y devenait « Rock &amp; Roll ». L'import
     * en masse, lui, n'échappait rien : le même morceau était enregistré
     * différemment selon la porte d'entrée. L'échappement appartient à
     * l'affichage, et c'est là qu'il a lieu maintenant (voir plus bas).
     */
    $title    = $trackTitle  ?: "Aucun titre";
    $artist   = $trackArtist ?: "Aucun artiste";
    $duration = $data['duration'] ?? "Aucune information";
    $thumb    = $data['thumbnails'][count($data['thumbnails'])-1]['url'] ?? '';

    // Le genre est parfois fourni par yt-dlp (tableau "genres" ou chaîne "genre")
    $genre = $data['genres'] ?? $data['genre'] ?? '';
    if (is_array($genre)) { $genre = implode(', ', $genre); }


    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("SELECT title FROM tracks WHERE title = :title");
    $req->bindParam(':title', $title);
    $req->execute();

    if (!$req->fetch()) {
        ?>
        <form data-action="../actions/import.php" id="import-check" class="containers" method="post">
            <label>
                <input type="text" class="alterable" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" name="title" readonly required>
            </label>

            <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="image">

            <label>Artiste :
                <input type="text" class="alterable" value="<?= htmlspecialchars($artist, ENT_QUOTES, 'UTF-8') ?>" name="artist" readonly required>
            </label>
            <br>

            <label>Genre :
                <input type="text" class="alterable" value="<?= htmlspecialchars($genre, ENT_QUOTES, 'UTF-8') ?>" name="genre" placeholder="Genre inconnu" readonly>
            </label>
            <br>

            <label>Durée :
                <input type="text" value="<?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?>" name="duration" readonly>
            </label>
            <br>

            <input type="hidden" value="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" name="miniature">
            <input type="hidden" value="<?php echo filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL); ?>" name="url">
            <input type="hidden" name="token" value="<?= $token; ?>">

            <div id="import-section_buttons">
                <label>Des modifications ? :
                    <input type="checkbox" id="edit-toggle">
                </label>
                <button type="submit" class="buttons">Charger</button>
            </div>
        </form>
    <?php } else {
        ?>
        <article class="containers">
            <div class="body-bar">
                <div class="content">
                    <em><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></em>&nbsp; existe déjà dans la bibliothèque.
                </div>
            </div>
        </article>
        <?php
    }
} ?>

<script>
    (function () {
        const toggle = document.getElementById('edit-toggle');

        // Vérifie que la checkbox existe avant d'attacher l'événement
        if (!toggle) return;

        toggle.addEventListener('change', function() {
            document.querySelectorAll('.alterable').forEach(input => {
                if (this.checked) {
                    input.removeAttribute('readonly');
                    input.focus();
                } else {
                    input.setAttribute('readonly', true);
                }
            });
        });
    })();
</script>

<!--
echo json_encode(['success' => true, 'message' => 'Importé avec succès']);
// ou en cas d'erreur :
echo json_encode(['success' => false, 'message' => "Erreur lors de l'import"]);
-->