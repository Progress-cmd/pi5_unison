<?php
session_start();

if (
    !isset($_POST['token'], $_SESSION['token']) ||
    $_POST['token'] !== $_SESSION['token']
) {
    die('Token invalide');
}

$name = filter_input(INPUT_POST, 'name', FILTER_DEFAULT);

include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("INSERT INTO playlists (name, `created-by_id`) VALUES (:name, :user)");
$req->bindParam(':name', $name);
$req->bindParam(':user', $_SESSION['user']['id']);
$req->execute();