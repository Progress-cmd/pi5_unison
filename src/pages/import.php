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
                Que souhaitez-vous importer ?
            </div>
        </div>
    </article>
<?php } else {
    if (
            !isset($_POST['token'], $_SESSION['token']) ||
            $_POST['token'] !== $_SESSION['token']
    ) {
        die('Token invalide');
    }

    $cmd = "yt-dlp --skip-download --no-playlist --dump-json ".escapeshellarg($lien);
    $lien = null;

    $json = shell_exec($cmd);
    if (is_null($json)) {
        die("Le lien n'est pas valide, aucune musique trouvée");
    }
    $data = json_decode($json, true);

    $title = $data['track'] ?? "Aucun titre";
    $artist = $data['artist'] ?? "Aucun artist";
    $album = $data['album'] ?? "Aucun album";
    $duration = $data['duration'] ?? "Aucune information";
    $thumb = $data['thumbnails'][count($data['thumbnails'])-1]['url'] ?? null;


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

            <label>Durée :
                <input type="text" value="<?php echo $duration ?>" name="duration" readonly>
            </label>
            <br>

            <input type="hidden" value="<?php echo $thumb ?>" name="miniature">
            <input type="hidden" value="<?php echo filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL); ?>" name="url">
            <input type="hidden" name="token" value="<?= filter_input(INPUT_POST, 'token', FILTER_DEFAULT); ?>">

            <div id="import-section_buttons">
                <label>Des modifications ? :
                    <input type="checkbox" id="edit-toggle">
                </label>
                <button type="submit" class="buttons">Charger</button>
            </div>
        </form>
    <?php } else {
        echo "<i>".$title."</i> existe déjà dans la base de donnée";
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