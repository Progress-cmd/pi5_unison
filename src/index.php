<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

include "header.php";
?>
<main id="main-content">
</main>

<?php
include "footer.php";
?>
