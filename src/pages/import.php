<?php
session_start();
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
    (function () {
        const btn = document.getElementById('bulk-import-btn');
        const textarea = document.getElementById('bulk-urls');
        const progress = document.getElementById('bulk-progress');
        if (!btn || !textarea) return;

        let running = false;

        function line(state, label) {
            const div = document.createElement('div');
            div.className = 'bulk-item bulk-' + state;
            div.innerHTML = `<span class="bulk-status material-symbols-outlined"></span><span class="bulk-label"></span>`;
            div.querySelector('.bulk-label').textContent = label;
            return div;
        }
        function setState(div, state) {
            div.className = 'bulk-item bulk-' + state;
        }

        btn.addEventListener('click', async () => {
            if (running) return;
            const text = textarea.value.trim();
            if (!text) { window.showToast('Collez au moins un lien', 'error'); return; }

            running = true;
            btn.disabled = true;
            btn.textContent = 'Analyse...';
            progress.innerHTML = '';

            // 1) Développe les liens (playlists incluses) en liste de vidéos
            let tracks = [];
            try {
                const res = await fetch('actions/import_expand.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'text=' + encodeURIComponent(text)
                });
                const data = await res.json();
                tracks = data.tracks || [];
            } catch (e) {
                window.showToast('Erreur lors de l\'analyse des liens', 'error');
            }

            if (tracks.length === 0) {
                window.showToast('Aucune vidéo trouvée', 'error');
                running = false; btn.disabled = false; btn.textContent = 'Importer tout';
                return;
            }

            // 2) Prépare l'affichage de progression
            const rows = tracks.map(t => {
                const div = line('pending', t.title);
                progress.appendChild(div);
                return div;
            });

            // 3) Importe chaque vidéo séquentiellement
            let ok = 0, fail = 0;
            for (let i = 0; i < tracks.length; i++) {
                setState(rows[i], 'loading');
                btn.textContent = `Import ${i + 1}/${tracks.length}`;
                try {
                    const res = await fetch('actions/import_bulk.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'url=' + encodeURIComponent(tracks[i].url)
                    });
                    const data = await res.json();
                    if (data.success) {
                        setState(rows[i], 'done');
                        if (data.title) rows[i].querySelector('.bulk-label').textContent =
                            data.title + ' — ' + data.artist;
                        ok++;
                    } else {
                        setState(rows[i], 'error');
                        fail++;
                    }
                } catch (e) {
                    setState(rows[i], 'error');
                    fail++;
                }
            }

            btn.textContent = 'Importer tout';
            btn.disabled = false;
            running = false;
            window.showToast(`${ok} importé(s)` + (fail ? `, ${fail} échec(s)` : ''),
                             fail ? 'error' : 'success');
        });
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

    $title    = htmlspecialchars($trackTitle       ?: "Aucun titre",        ENT_QUOTES, 'UTF-8');
    $artist   = htmlspecialchars($trackArtist       ?: "Aucun artiste",      ENT_QUOTES, 'UTF-8');
    $album    = htmlspecialchars($data['album']    ?? "Aucun album",        ENT_QUOTES, 'UTF-8');
    $duration = htmlspecialchars($data['duration'] ?? "Aucune information", ENT_QUOTES, 'UTF-8');
    $thumb    = htmlspecialchars($data['thumbnails'][count($data['thumbnails'])-1]['url'] ?? '', ENT_QUOTES, 'UTF-8');

    // Le genre est parfois fourni par yt-dlp (tableau "genres" ou chaîne "genre")
    $genreBrut = $data['genres'] ?? $data['genre'] ?? '';
    if (is_array($genreBrut)) { $genreBrut = implode(', ', $genreBrut); }
    $genre = htmlspecialchars($genreBrut, ENT_QUOTES, 'UTF-8');


    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("SELECT title FROM tracks WHERE title = :title");
    $req->bindParam(':title', $title);
    $req->execute();

    if (!$req->fetch()) {
        ?>
        <form data-action="../actions/import.php" id="import-check" class="containers" method="post">
            <label>
                <input type="text" class="alterable" value="<?php echo $title ?>" name="title" readonly required>
            </label>

            <img src="<?php echo $thumb ?>" alt="image">

            <label>Artiste :
                <input type="text" class="alterable" value="<?php echo $artist ?>" name="artist" readonly required>
            </label>
            <br>

            <label>Album :
                <input type="text" class="alterable" value="<?php echo $album ?>" name="album" readonly>
            </label>
            <br>

            <label>Genre :
                <input type="text" class="alterable" value="<?php echo $genre ?>" name="genre" placeholder="Genre inconnu" readonly>
            </label>
            <br>

            <label>Durée :
                <input type="text" value="<?php echo $duration ?>" name="duration" readonly>
            </label>
            <br>

            <input type="hidden" value="<?php echo $thumb ?>" name="miniature">
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
                    <em><?= $title ?></em>&nbsp; existe déjà dans la bibliothèque.
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