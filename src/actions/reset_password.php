<?php
session_start();

if (
    !isset($_POST['token'], $_SESSION['token']) ||
    $_POST['token'] !== $_SESSION['token']
) {
    die('Token invalide');
}

$username = filter_input(INPUT_POST, 'user', FILTER_DEFAULT);
$password = filter_input(INPUT_POST, 'password', FILTER_DEFAULT);
$password_confirm = filter_input(INPUT_POST, 'password-confirm', FILTER_DEFAULT);

if ($username != NULL) {

    include_once "../includes/config.php";
    $pdo = new PDO("mysql:host=" . config::$HOST . ";dbname=" . Config::$NAME, Config::$USER, Config::$PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $req = $pdo->prepare("UPDATE users SET `password-hash` = :password WHERE username = :username");
    $req->bindParam(':password', $hashedPassword);
    $req->bindParam(':username', $username);
    $req->execute();
}
header('Location: ../login.php');