<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

include "header.php";
?>
<main>
    <form class="search-form"  id="search-form">
        <button class="material-icons" type="submit">search</button>
        <input type="text" placeholder="Search" id="search-entry" required>
    </form>

    <div id="search-results"></div>
    <script src="scripts/search.js"></script>
</main>
<?php
include "footer.php";
?>