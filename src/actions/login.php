<?php
session_start();

if (
    !isset($_POST['token'], $_SESSION['token']) ||
    $_POST['token'] !== $_SESSION['token']
) {
    die('Token invalide');
}

$username = filter_input(INPUT_POST, 'selectedUser', FILTER_DEFAULT);
$password = filter_input(INPUT_POST, 'password', FILTER_DEFAULT);

include_once "../includes/config.php";
$pdo = new PDO("mysql:host=".config::$HOST.";dbname=".Config::$NAME, Config::$USER, Config::$PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

$req = $pdo->prepare("SELECT id, username, email, `password-hash` FROM users WHERE username = :username");
$req->bindValue(':username', $username);
$req->execute();

$user = $req->fetch();


if ($user != NULL && password_verify($password, $user['password-hash']) && $user['password-hash'] != NULL) {
    session_regenerate_id(true);

    $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email']];
}
else {
    echo "Identifiant ou mot de passe incorrect";
}

header("Location: ../");