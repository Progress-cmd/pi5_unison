<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

echo "Connecté !";

echo "<button onclick=".session_destroy().">Logout</button>";
?>
