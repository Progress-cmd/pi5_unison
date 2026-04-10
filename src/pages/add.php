<?php
$lien = filter_input(INPUT_POST, 'url', FILTER_DEFAULT);

if (is_null($lien)) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
    $token = $_SESSION['token'];
    session_write_close();
    ?>
    <form action="" class="add-form" method="post">
        <button class="material-icons" type="submit">manage_search</button>
        <input type="url" name="url" placeholder="Lien Youtube" required>

        <input type="hidden" name="token" value="<?= $token; ?>">
    </form>
<?php } else {
    $cmd = "yt-dlp --skip-download --no-playlist --dump-json ".escapeshellarg($lien);
    $lien = null;

    $json = shell_exec($cmd);
    if (is_null($json)) {
        die("Le lien n'est pas valide, aucune musique trouvée");
    }
    $data = json_decode($json, true);

    $title = $data['track'] ?? null;
    $artist = $data['artist'] ?? null;
    $album = $data['album'] ?? null;
    $duration = $data['duration'] ?? 0;
    $thumb = $data['thumbnails'][count($data['thumbnails'])-1]['url'] ?? null;


    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("SELECT title FROM tracks WHERE title = :title");
    $req->bindParam(':title', $title);
    $req->execute();

    if (!$req->fetch()) {
        ?>
        <form action="" class="add-form" id="verif-form" method="post">
            <label>Titre :
                <input type="text" value="<?php echo $title ?>" name="title" readonly>
            </label>
            <br>

            <label>Artiste :
                <input type="text" value="<?php echo $artist ?>" name="artist" readonly>
            </label>
            <br>

            <label>Album :
                <input type="text" value="<?php echo $album ?>" name="album" readonly>
            </label>
            <br>

            <label>Durée :
                <input type="text" value="<?php echo $duration ?>" name="duration" readonly>
            </label>
            <br>

            <img src="<?php echo $thumb ?>" alt="image">

            <input type="hidden" value="<?php echo $thumb ?>" name="miniature">
            <input type="hidden" value="<?php echo $_POST['url'] ?>" name="url">
            <input type="hidden" name="token" id="csrf-token" value="">

            <button type="submit">Valider</button>
        </form>
    <?php } else {
        echo "<i>".$title."</i> est déjà dans la base de donnée";
    }
} ?>
<script src="../scripts/add.js"></script>