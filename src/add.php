<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$titre = $_POST["titre"] ?? false;

include "header.php";
?>
<main>
    <?php if (!$titre) { ?>
        <form action="#" method="post">
            <label>
                Musique à chercher
                <input type="text" name="titre">
            </label>
            <button type="submit">Envoyer</button>
        </form>
    <?php } else { ?>
        <form action="actions/add.php" method="post">
            <label>
                Titre
                <input type="text" readonly value="" name="title">
            </label>
            <label>
                Artiste
                <input type="text" readonly value="" name="artist">
            </label>
            <button type="submit">Valider</button>
        </form>
    <?php } ?>
</main>

<?php
include "footer.php";
?>