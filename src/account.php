<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

include "header.php";
?>
<main>
    <form action="actions/logout.php">
        <button type="submit">Déconnexion</button>
    </form>
</main>

<?php
include "footer.php";
?>