<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

include "header.php";
?>
<main>
    <form action="actions/search.php" class="search-form" method="post">
        <button class="material-icons" type="submit">search</button>
        <input type="text" placeholder="Search" required>
    </form>
    <p><?php echo $_GET["test"] ?? ""; ?></p>
</main>
<?php
include "footer.php";
?>